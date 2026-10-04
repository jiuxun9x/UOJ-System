"""A mail server that keeps what it is given, for the tests of what the site sends.

It runs inside the test process, and the web server of UOJ reaches it as host.docker.internal.
"""

import base64
import email
import email.header
import socketserver
import threading

PORT = 18025
# how the web server of UOJ, in its container, reaches this process
HOST = "host.docker.internal"


class Message:
    def __init__(self, user, password, sender, recipients, data):
        self.user, self.password, self.sender, self.recipients, self.data = user, password, sender, recipients, data
        self.parsed = email.message_from_string(data)

    @property
    def subject(self):
        return "".join(
            part.decode(charset or "utf-8") if isinstance(part, bytes) else part
            for part, charset in email.header.decode_header(self.parsed["Subject"])
        )

    @property
    def text(self):
        """every part of the mail that is text, decoded"""
        return "\n".join(
            part.get_payload(decode=True).decode(part.get_content_charset() or "utf-8", errors="replace")
            for part in self.parsed.walk()
            if part.get_content_maintype() == "text"
        )


class MockSMTP:
    def __init__(self):
        self.messages = []
        # when set, a user who logs in with another password is turned away
        self.password = None
        self.server = None

    def start(self):
        smtp = self

        class Handler(socketserver.StreamRequestHandler):
            def reply(self, line):
                self.wfile.write((line + "\r\n").encode())

            def line(self):
                return self.rfile.readline().decode(errors="replace").rstrip("\r\n")

            def handle(self):
                unbase64 = lambda text: base64.b64decode(text).decode(errors="replace")
                user = password = sender = None
                recipients = []
                self.reply("220 mock.smtp ESMTP")
                while True:
                    line = self.line()
                    if not line:
                        return
                    words = line.split(" ")
                    verb = words[0].upper()
                    if verb in ("EHLO", "HELO"):
                        self.reply("250-mock.smtp")
                        self.reply("250-AUTH LOGIN PLAIN")
                        self.reply("250 8BITMIME")
                    elif verb == "AUTH" and len(words) > 1 and words[1].upper() == "LOGIN":
                        self.reply("334 VXNlcm5hbWU6")
                        user = unbase64(self.line())
                        self.reply("334 UGFzc3dvcmQ6")
                        password = unbase64(self.line())
                        if smtp.password is not None and password != smtp.password:
                            user = password = None
                            self.reply("535 5.7.8 the mailbox does not know this password")
                        else:
                            self.reply("235 2.7.0 welcome")
                    elif verb == "MAIL":
                        sender = line[line.find("<") + 1 : line.find(">")]
                        self.reply("250 ok")
                    elif verb == "RCPT":
                        recipients.append(line[line.find("<") + 1 : line.find(">")])
                        self.reply("250 ok")
                    elif verb == "DATA":
                        self.reply("354 go on")
                        lines = []
                        while True:
                            data_line = self.rfile.readline().decode(errors="replace").rstrip("\r\n")
                            if data_line == ".":
                                break
                            lines.append(data_line[1:] if data_line.startswith("..") else data_line)
                        smtp.messages.append(Message(user, password, sender, recipients, "\n".join(lines)))
                        sender, recipients = None, []
                        self.reply("250 kept")
                    elif verb in ("RSET", "NOOP"):
                        self.reply("250 ok")
                    elif verb == "QUIT":
                        self.reply("221 bye")
                        return
                    else:
                        self.reply("502 not spoken here")

        socketserver.ThreadingTCPServer.allow_reuse_address = True
        self.server = socketserver.ThreadingTCPServer(("0.0.0.0", PORT), Handler)
        self.server.daemon_threads = True
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def stop(self):
        if self.server:
            self.server.shutdown()
            self.server.server_close()
            self.server = None
