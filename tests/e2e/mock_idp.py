"""A school that speaks CAS and OAuth 2.0 / OpenID Connect, for the tests of the single sign-on.

It runs inside the test process, and the web server of UOJ reaches it as host.docker.internal.
The tests play the browser: they take the address UOJ sends the browser to, ask this school for
a ticket or a code as if a user had logged in there, and carry it back to UOJ.
"""

import base64
import hashlib
import json
import secrets
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse
from xml.sax.saxutils import escape

PORT = 18089
# how the web server of UOJ, in its container, reaches this process
URL = "http://host.docker.internal:%d" % PORT
CLIENT_ID = "uoj-e2e"
CLIENT_SECRET = "e2e-client-secret"


class MockIdP:
    def __init__(self):
        self.cas_tickets = {}
        self.oauth_codes = {}
        self.oauth_tokens = {}
        # every request UOJ made, as (method, path, parameters)
        self.requests = []
        self.server = None

    def start(self):
        idp = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                url = urlparse(self.path)
                idp.handle(self, "GET", url.path, {k: v[0] for k, v in parse_qs(url.query).items()})

            def do_POST(self):
                body = self.rfile.read(int(self.headers.get("Content-Length", "0"))).decode()
                idp.handle(self, "POST", urlparse(self.path).path, {k: v[0] for k, v in parse_qs(body).items()})

        self.server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def stop(self):
        if self.server is not None:
            self.server.shutdown()
            self.server.server_close()

    # ---- what a user who logs in at the school is handed

    def cas_ticket(self, service, user, **attributes):
        ticket = "ST-" + secrets.token_urlsafe(24)
        self.cas_tickets[ticket] = (service, user, attributes)
        return ticket

    def oauth_code(self, authorize_query, profile):
        """authorize_query: the parameters UOJ sent the browser to the school with"""
        assert authorize_query["response_type"] == "code", authorize_query
        assert authorize_query["client_id"] == CLIENT_ID, authorize_query
        code = secrets.token_urlsafe(24)
        self.oauth_codes[code] = (authorize_query, profile)
        return code

    # ---- what UOJ asks the school

    def handle(self, request, method, path, params):
        self.requests.append((method, path, dict(params)))
        if path in ("/cas/p3/serviceValidate", "/cas/serviceValidate"):
            return self.reply(request, 200, "application/xml", self.cas_validate(params))
        if path == "/oidc/.well-known/openid-configuration":
            return self.reply_json(request, 200, {
                "issuer": URL + "/oidc",
                "authorization_endpoint": URL + "/oauth/authorize",
                "token_endpoint": URL + "/oauth/token",
                "userinfo_endpoint": URL + "/oauth/userinfo",
            })  # fmt: skip
        if path == "/oauth/token" and method == "POST":
            return self.oauth_token(request, params)
        if path == "/oauth/userinfo":
            authorization = request.headers.get("Authorization", "")
            profile = self.oauth_tokens.get(authorization[len("Bearer ") :]) if authorization.startswith("Bearer ") else None
            if profile is None:
                return self.reply_json(request, 401, {"error": "invalid_token"})
            return self.reply_json(request, 200, profile)
        return self.reply(request, 404, "text/plain", "not found")

    def cas_validate(self, params):
        failure = (
            '<cas:serviceResponse xmlns:cas="http://www.yale.edu/tp/cas">'
            '<cas:authenticationFailure code="%s">%s</cas:authenticationFailure>'
            "</cas:serviceResponse>"
        )
        # a ticket is good for one validation, for the service it was issued for
        issued = self.cas_tickets.pop(params.get("ticket", ""), None)
        if issued is None:
            return failure % ("INVALID_TICKET", "ticket not recognized")
        service, user, attributes = issued
        if params.get("service") != service:
            return failure % ("INVALID_SERVICE", "ticket was issued for another service")
        attributes = "".join("<cas:%s>%s</cas:%s>" % (key, escape(value), key) for key, value in attributes.items())
        return (
            '<cas:serviceResponse xmlns:cas="http://www.yale.edu/tp/cas">'
            "<cas:authenticationSuccess><cas:user>%s</cas:user><cas:attributes>%s</cas:attributes>"
            "</cas:authenticationSuccess></cas:serviceResponse>" % (escape(user), attributes)
        )

    def oauth_token(self, request, params):
        if params.get("client_id") != CLIENT_ID or params.get("client_secret") != CLIENT_SECRET:
            return self.reply_json(request, 401, {"error": "invalid_client"})
        # a code is good for one exchange
        issued = self.oauth_codes.pop(params.get("code", ""), None)
        if issued is None or params.get("grant_type") != "authorization_code":
            return self.reply_json(request, 400, {"error": "invalid_grant"})
        authorize_query, profile = issued
        if params.get("redirect_uri") != authorize_query["redirect_uri"]:
            return self.reply_json(request, 400, {"error": "invalid_grant"})
        if "code_challenge" in authorize_query:
            digest = hashlib.sha256(params.get("code_verifier", "").encode()).digest()
            if base64.urlsafe_b64encode(digest).decode().rstrip("=") != authorize_query["code_challenge"]:
                return self.reply_json(request, 400, {"error": "invalid_grant"})
        token = secrets.token_urlsafe(24)
        self.oauth_tokens[token] = profile
        return self.reply_json(request, 200, {"access_token": token, "token_type": "Bearer", "expires_in": 300})

    def reply_json(self, request, status, value):
        self.reply(request, status, "application/json", json.dumps(value))

    def reply(self, request, status, content_type, body):
        body = body.encode()
        request.send_response(status)
        request.send_header("Content-Type", content_type + "; charset=utf-8")
        request.send_header("Content-Length", str(len(body)))
        request.end_headers()
        request.wfile.write(body)


# The providers of this school in the configuration of UOJ. configure.py writes them.
PROVIDERS_PHP = """
		'cas' => [
			'type' => 'cas',
			'name' => '校园统一身份认证',
			'server' => '%(url)s/cas',
			// the school calls a user by a login name, the student number is an attribute
			'attributes' => ['student_id' => 'employeeNumber']
		],
		'oauth' => [
			'type' => 'oauth2',
			'name' => '校园 OAuth',
			'client_id' => '%(client_id)s',
			'client_secret' => '%(client_secret)s',
			'authorize_url' => '%(url)s/oauth/authorize',
			'token_url' => '%(url)s/oauth/token',
			'userinfo_url' => '%(url)s/oauth/userinfo',
			'attributes' => ['external_id' => 'data.uid', 'student_id' => 'data.number', 'real_name' => 'data.name', 'email' => 'data.email']
		],
		'oidc' => [
			'type' => 'oidc',
			'name' => '校园 OIDC',
			'issuer' => '%(url)s/oidc',
			'client_id' => '%(client_id)s',
			'client_secret' => '%(client_secret)s'
		]
	""" % {"url": URL, "client_id": CLIENT_ID, "client_secret": CLIENT_SECRET}
