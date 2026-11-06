#include <argp.h>

#include <cstdio>
#include <fstream>
#include <iostream>
#include <sstream>
#include <stdexcept>
#include <string>
#include <regex>
#include <utility>
#include <vector>

#include "uoj_run.h"

namespace fs = std::filesystem;

class language_not_supported_error : public std::invalid_argument {
public:
    explicit language_not_supported_error() :
        std::invalid_argument("This language has not been supported yet.") {}
};

class fail_to_read_src_error : public std::runtime_error {
public:
    explicit fail_to_read_src_error(
        const std::string &what = "An error occurs when trying to read the source code.") :
        std::runtime_error(what) {}
};

class compile_error : public std::invalid_argument {
public:
    explicit compile_error(const std::string &what) : std::invalid_argument(what) {}
};

const std::vector<std::pair<const char *, const char *>> suffix_search_list = {
    {".code", ""},
    // C++
    {"98.cpp", "C++98"},
    {"03.cpp", "C++03"},
    {"11.cpp", "C++11"},
    {"14.cpp", "C++14"},
    {"17.cpp", "C++17"},
    {"20.cpp", "C++20"},
    {"23.cpp", "C++23"},
    {"26.cpp", "C++26"},
    {".cpp", "C++17"},
    // C
    {"89.c", "C89"},
    {"99.c", "C99"},
    {"11.c", "C11"},
    {"17.c", "C17"},
    {"23.c", "C23"},
    {".c", "C17"},
    // Java
    {"8.java", "Java8"},
    {"11.java", "Java11"},
    {"17.java", "Java17"},
    {"21.java", "Java21"},
    {".java", "Java17"},
    // Others
    {".pas", "Pascal"},
    {"2.py", "Python2"},
    {".py", "Python3"},
};

// Precompiled <bits/stdc++.h>, one for each standard of C++, in <UOJ_PCH_DIR>/<standard>/bits/.
// A program whose first line is #include <bits/stdc++.h> is compiled with the folder of its
// standard ahead of the others; gcc then reads the header already compiled instead of compiling
// it again, which is most of the time a small program takes to compile. gcc takes a precompiled
// header only where it was made with the same options, and otherwise goes on to the header
// itself, so both are made with cpp_options() below. A program with anything before the
// #include, such as a #define or a #pragma that could change what the header means, is compiled
// the ordinary way.
#define UOJ_PCH_DIR "/usr/local/lib/uoj-pch"
const std::vector<std::string> pch_standards = {"c++98", "c++03", "c++11", "c++14",
                                                "c++17", "c++20", "c++23", "c++26"};

struct compile_config {
    std::string name;
    std::string src;
    std::string lang = "auto";
    std::string opt;
    std::string implementer;
    std::string custom_compiler_path;
    std::vector<std::string> cinclude_dirs;
    // macros that a program of C or C++ is compiled with, such as DOMJUDGE for an output
    // validator written for DOMjudge
    std::vector<std::string> defines;
    // whether a precompiled header may be used
    bool pch = true;
    // whether the options the compiler is run with are written to stderr
    bool verbose = false;

    void auto_find_src() {
        if (!src.empty()) {
            return;
        }
        for (auto &p : suffix_search_list) {
            if (fs::is_regular_file(name + p.first)) {
                src = fs::canonical(name + p.first);
                if (lang == "auto") {
                    lang = p.second;
                }
                return;
            }
        }
    }
};

error_t compile_argp_parse_opt(int key, char *arg, struct argp_state *state) {
    compile_config *config = (compile_config *)state->input;

    try {
        switch (key) {
            case 's':
                config->src = arg;
                break;
            case 'i':
                config->implementer = arg;
                break;
            case 'l':
                config->lang = arg;
                break;
            case 'c':
                config->custom_compiler_path = arg;
                break;
            case 'I':
                config->cinclude_dirs.push_back(arg);
                break;
            case 'D':
                if (!std::regex_match(std::string(arg), std::regex("[A-Za-z_][A-Za-z0-9_]{0,63}"))) {
                    argp_usage(state);
                }
                config->defines.push_back(arg);
                break;
            case 'P':
                config->pch = false;
                break;
            case 'v':
                config->verbose = true;
                break;
            case ARGP_KEY_ARG:
                config->name = arg;
                break;
            case ARGP_KEY_END:
                if (state->arg_num != 1) {
                    argp_usage(state);
                }
                break;
            default:
                return ARGP_ERR_UNKNOWN;
        }
    } catch (std::exception &e) {
        argp_usage(state);
    }

    return 0;
}

compile_config parse_args(int argc, char **argv) {
    argp_option argp_options[] = {
        {"src", 's', "SOURCE_CODE", 0, "set the path to source code", 1},
        {"impl", 'i', "IMPLEMENTER", 0, "set the implementer name", 2},
        {"lang", 'l', "LANGUAGE", 0, "set the language", 3},
        {"custom", 'c', "CUSTOM", 0, "path to custom compilers (those are not placed in /usr/bin)",
         4},
        {"cinclude", 'I', "DIRECTORY", 0,
         "add the directory dir to the list of directories to be searched for header files during "
         "preprocessing (for C/C++)",
         5},
        {"define", 'D', "NAME", 0, "define a macro (for C/C++)", 6},
        {"no-pch", 'P', 0, 0, "never use a precompiled header (for C++)", 7},
        {"verbose", 'v', 0, 0, "write the options of the compiler to stderr (for C/C++)", 8},
        {0}};
    char argp_args_doc[] = "name";
    char argp_doc[] = "compile: a tool to compile programs";

    argp compile_argp = {argp_options, compile_argp_parse_opt, argp_args_doc, argp_doc};

    compile_config config;
    argp_parse(&compile_argp, argc, argv, ARGP_NO_ARGS | ARGP_IN_ORDER, 0, &config);

    config.auto_find_src();
    if (config.src.empty() || !fs::is_regular_file(config.src)) {
        throw fail_to_read_src_error();
    }

    return config;
}

bool is_illegal_keyword(const std::string &name) {
    return name == "__asm" || name == "__asm__" || name == "asm";
}

bool has_illegal_keywords_in_file(const std::string &src) {
    std::ifstream fin(src);
    if (!fin) {
        throw fail_to_read_src_error();
    }

    const int L = 1 << 15;
    char buf[L];
    std::string key;

    while (!fin.eof()) {
        fin.read(buf, L);
        int cnt = fin.gcount();
        for (char *p = buf; p != buf + cnt; p++) {
            char c = *p;
            if (isalnum(c) || c == '_') {
                if (key.size() < 20) {
                    key += c;
                } else {
                    if (is_illegal_keyword(key)) {
                        return true;
                    }
                    key.erase(key.begin());
                    key += c;
                }
            } else {
                if (is_illegal_keyword(key)) {
                    return true;
                }
                key.clear();
            }
        }
        if (fin.bad()) {
            throw fail_to_read_src_error();
        }
    }
    return false;
}

std::string get_java_main_class(const std::string &src) {
    std::ifstream fin(src);
    if (!fin) {
        throw fail_to_read_src_error();
    }

    const int L = 1 << 15;
    char buf[L];
    std::string s;

    int mode = 0;

    while (!fin.eof()) {
        fin.read(buf, L);
        int cnt = fin.gcount();
        for (char *p = buf; p != buf + cnt; p++) {
            s += *p;
            switch (mode) {
                case 0:
                    switch (*p) {
                        case '/':
                            mode = 1;
                            break;
                        case '\'':
                            mode = 5;
                            break;
                        case '\"':
                            mode = 6;
                            break;
                    }
                    break;
                case 1:
                    switch (*p) {
                        case '/':
                            mode = 2;
                            s.pop_back();
                            s.pop_back();
                            break;
                        case '*':
                            mode = 3;
                            s.pop_back();
                            s.pop_back();
                            break;
                    }
                    break;
                case 2:
                    s.pop_back();
                    switch (*p) {
                        case '\n':
                            s += '\n';
                            mode = 0;
                            break;
                    }
                    break;
                case 3:
                    s.pop_back();
                    switch (*p) {
                        case '*':
                            mode = 4;
                            break;
                    }
                    break;
                case 4:
                    s.pop_back();
                    switch (*p) {
                        case '/':
                            s += ' ';
                            mode = 0;
                            break;
                    }
                    break;
                case 5:
                    switch (*p) {
                        case '\'':
                            mode = 0;
                            break;
                        case '\\':
                            mode = 7;
                            break;
                    }
                case 6:
                    switch (*p) {
                        case '\"':
                            mode = 0;
                            break;
                        case '\\':
                            mode = 8;
                            break;
                    }
                case 7:
                    mode = 5;
                    break;
                case 8:
                    mode = 6;
                    break;
            }
        }
        if (fin.bad()) {
            throw fail_to_read_src_error();
        }
    }

    bool valid[256];
    std::fill(valid, valid + 256, false);
    std::fill(valid + 'a', valid + 'z' + 1, true);
    std::fill(valid + 'A', valid + 'Z' + 1, true);
    std::fill(valid + '0', valid + '9' + 1, true);
    valid['.'] = true;
    valid['_'] = true;

    std::vector<std::string> tokens;
    for (size_t p = 0, np = 0; p < s.length(); p = np) {
        while (np < s.length() && valid[(unsigned char)s[np]]) {
            np++;
        }
        if (np == p) {
            np++;
        } else {
            tokens.push_back(s.substr(p, np - p));
        }
    }
    if (tokens.size() > 0 && tokens[0] == "package") {
        throw compile_error("Please don't specify the package.");
    }

    for (size_t i = 0; i + 1 < tokens.size(); i++) {
        if (tokens[i] == "class") {
            std::string name = tokens[i + 1];
            if (name.length() > 100) {
                throw compile_error("The name of the main class is too long.");
            }
            for (size_t k = 0; k < name.length(); k++) {
                if (!isalnum(name[k]) && name[k] != '_') {
                    throw compile_error(
                        "The name of the main class should only contain letters, numbers and "
                        "underscore sign.");
                }
            }
            if (!isalpha(name[0])) {
                throw compile_error("The name of the main class cannot begin with a number.");
            }
            return tokens[i + 1];
        }
    }

    throw compile_error("Cannot find the main class.");
}

// the options a program of C++ is compiled with, but where it is and where it goes
std::string cpp_options(const std::string &std) {
    std::ostringstream sout;
    spaced_out(sout, "-O2", "-DONLINE_JUDGE", "-std=" + std);
    return sout.str();
}

// Whether the first line of a program that is not empty and not a comment is
// #include <bits/stdc++.h>. Where that can not be told for sure, it is not.
bool starts_with_bits_include(const std::string &src) {
    std::ifstream fin(src, std::ios::binary);
    if (!fin) {
        return false;
    }
    std::string text(1 << 16, '\0');
    fin.read(&text[0], text.size());
    text.resize(fin.gcount());

    size_t p = 0;
    if (text.compare(0, 3, "\xEF\xBB\xBF") == 0) {
        p = 3;
    }
    while (p < text.size()) {
        char c = text[p];
        if (c == ' ' || c == '\t' || c == '\r' || c == '\n' || c == '\f' || c == '\v') {
            p++;
        } else if (text.compare(p, 2, "//") == 0) {
            size_t end = text.find('\n', p);
            if (end == std::string::npos) {
                return false;
            }
            // a backslash at the end makes the next line part of the comment, and so does ??/
            // where trigraphs are read (-std=c++14 and older)
            size_t last = end;
            while (last > p && (text[last - 1] == '\r' || text[last - 1] == ' ' || text[last - 1] == '\t')) {
                last--;
            }
            if (last > p && text[last - 1] == '\\') {
                return false;
            }
            if (last >= p + 3 && text.compare(last - 3, 3, "?\?/") == 0) {
                return false;
            }
            p = end + 1;
        } else if (text.compare(p, 2, "/*") == 0) {
            size_t end = text.find("*/", p + 2);
            if (end == std::string::npos) {
                return false;
            }
            p = end + 2;
        } else {
            break;
        }
    }
    size_t end = text.find('\n', p);
    if (end == std::string::npos) {
        return false;
    }
    static const std::regex bits_include(
        "#[ \t]*include[ \t]*<bits/stdc\\+\\+\\.h>[ \t]*(//[^\\\\]*)?\r?");
    return std::regex_match(text.substr(p, end - p), bits_include);
}

// the folder with the precompiled header that a program is compiled with, or "" for none
std::string pch_dir_for(const compile_config &conf, const std::string &std) {
    if (!conf.pch || !conf.implementer.empty()) {
        return "";
    }
    fs::path dir = fs::path(UOJ_PCH_DIR) / std;
    std::error_code ec;
    if (!fs::is_regular_file(dir / "bits" / "stdc++.h.gch", ec) || !starts_with_bits_include(conf.src)) {
        return "";
    }
    return dir.string();
}

// Makes the precompiled headers, from the header that #include <bits/stdc++.h> finds.
int make_pch(const std::string &dir, const std::string &header) {
    for (const auto &std : pch_standards) {
        fs::path target = fs::path(dir) / std / "bits";
        fs::create_directories(target);
        int ret = execute(UOJ_GPLUSPLUS, cpp_options(std), "-x", "c++-header", escapeshellarg(header),
                          "-o", escapeshellarg((target / "stdc++.h.gch").string()));
        if (ret != 0) {
            std::cerr << "failed to precompile " << header << " for " << std << std::endl;
            fs::remove_all(fs::path(dir) / std);
            return 1;
        }
    }
    return 0;
}

int compile_cpp(const compile_config &conf, const std::string &std) {
    std::ostringstream sflags;
    spaced_out(sflags, "-lm", cpp_options(std));
    std::string pch_dir = pch_dir_for(conf, std);
    if (!pch_dir.empty()) {
        add_spaced_out(sflags, "-I" + pch_dir);
    }
    for (auto dir : conf.cinclude_dirs) {
        add_spaced_out(sflags, "-I" + dir);
    }
    for (const auto &name : conf.defines) {
        add_spaced_out(sflags, "-D" + name);
    }
    if (conf.verbose) {
        std::cerr << sflags.str() << std::endl;
    }

    if (conf.implementer.empty()) {
        return execute(UOJ_GPLUSPLUS, sflags.str(), "-o", conf.name, "-x", "c++", conf.src);
    } else {
        return execute(UOJ_GPLUSPLUS, sflags.str(), "-o", conf.name, conf.implementer + ".cpp",
                       "-x", "c++", conf.src);
    }
}
int compile_c(const compile_config &conf, const std::string &std) {
    std::ostringstream sflags;
    spaced_out(sflags, "-lm", "-O2", "-DONLINE_JUDGE", "-std=" + std);
    for (auto dir : conf.cinclude_dirs) {
        add_spaced_out(sflags, "-I" + dir);
    }
    for (const auto &name : conf.defines) {
        add_spaced_out(sflags, "-D" + name);
    }
    if (conf.verbose) {
        std::cerr << sflags.str() << std::endl;
    }

    if (conf.implementer.empty()) {
        return execute(UOJ_GCC, sflags.str(), "-o", conf.name, "-x", "c", conf.src);
    } else {
        return execute(UOJ_GCC, sflags.str(), "-o", conf.name, conf.implementer + ".c", "-x", "c",
                       conf.src);
    }
}
int compile_python2_7(const compile_config &conf) {
    if (!conf.implementer.empty()) {
        throw language_not_supported_error();
    }

    std::string dfile = "__pycode__/" + conf.name + ".py";
    std::string compiler_code =
		"import py_compile\n"
		"import sys\n"
		"try:\n"
		"    py_compile.compile('" + conf.src + "', cfile='" + conf.name + "', dfile='" + dfile + "', doraise=True)\n"
		"    sys.exit(0)\n"
		"except Exception as e:\n"
		"    print e\n"
		"    sys.exit(1)\n";
    return execute(UOJ_PYTHON2, "-E", "-s", "-B", "-O", "-c", escapeshellarg(compiler_code));
}
int compile_python3(const compile_config &conf) {
    if (!conf.implementer.empty()) {
        throw language_not_supported_error();
    }
    std::string dfile = "__pycode__/" + conf.name + ".py";
    std::string compiler_code =
		"import py_compile\n"
		"import sys\n"
		"try:\n"
		"    py_compile.compile('" + conf.src + "', cfile='" + conf.name + "', dfile='" + dfile + "', doraise=True)\n"
		"    sys.exit(0)\n"
		"except Exception as e:\n"
		"    print(e)\n"
		"    sys.exit(1)\n";
    return execute(UOJ_PYTHON3, "-I", "-B", "-O", "-c", escapeshellarg(compiler_code));
}
int compile_java(const compile_config &conf, const std::string &version) {
    if (!conf.implementer.empty()) {
        throw language_not_supported_error();
    }

    try {
        std::string main_class = get_java_main_class(conf.src);
        fs::remove_all(conf.name);
        fs::create_directory(conf.name);
        fs::copy_file(conf.src, fs::path(conf.name) / (main_class + ".java"));
        // javac is a JVM, which takes a heap of a quarter of the memory of the machine and
        // reserves it at once: more than the address space a compiler may have, and javac failed
        // for want of memory every time. A program is compiled in far less than this (and with
        // one processor the JVM makes no threads, nor their memory, for many).
        int ret = execute("cd", conf.name, "&&", UOJ_JDK "/bin/javac", "-J-Xmx1024m",
                          "-J-XX:ActiveProcessorCount=1", "--release", version,
                          main_class + ".java");
        fs::remove(fs::path(conf.name) / (main_class + ".java"));
        put_class_name_to_file(fs::path(conf.name) / ".main_class_name", main_class);
        return ret;
    } catch (std::system_error &e) {
        throw compile_error("System Error");
    }
}
int compile_pas(const compile_config &conf) {
    if (conf.implementer.empty()) {
        fs::path src_path(conf.src);
        fs::path dir = src_path.parent_path();
        fs::path file = src_path.filename();
        return execute("cd", dir.string(), "&&", UOJ_FPC, file.string(), "-O2");
    } else {
        try {
            std::string unit_name = get_class_name_from_file(conf.name + ".unit_name");
            if (!unit_name.empty()) {
                fs::copy_file(conf.src, unit_name + ".pas");
            }
            int ret = execute(UOJ_FPC, conf.implementer + ".pas", "-o" + conf.name, "-O2");
            if (!unit_name.empty()) {
                fs::remove(unit_name + ".pas");
            }
            return ret;
        } catch (std::system_error &e) {
            throw compile_error("System Error");
        }
    }
}

int compile(const compile_config &conf) {
    std::string lang = upgraded_lang(conf.lang);

    if ((lang.length() > 0 && lang[0] == 'C') && has_illegal_keywords_in_file(conf.src)) {
        std::cerr << "Compile Failed: assembly language detected" << std::endl;
        return 1;
    }

    if (lang == "C++98") {
        return compile_cpp(conf, "c++98");
    } else if (lang == "C++03") {
        return compile_cpp(conf, "c++03");
    } else if (lang == "C++11") {
        return compile_cpp(conf, "c++11");
    } else if (lang == "C++14") {
        return compile_cpp(conf, "c++14");
    } else if (lang == "C++17") {
        return compile_cpp(conf, "c++17");
    } else if (lang == "C++20") {
        return compile_cpp(conf, "c++20");
    } else if (lang == "C++23") {
        return compile_cpp(conf, "c++23");
    } else if (lang == "C++26") {
        return compile_cpp(conf, "c++26");
    } else if (lang == "C89") {
        return compile_c(conf, "c89");
    } else if (lang == "C99") {
        return compile_c(conf, "c99");
    } else if (lang == "C11") {
        return compile_c(conf, "c11");
    } else if (lang == "C17") {
        return compile_c(conf, "c17");
    } else if (lang == "C23") {
        return compile_c(conf, "c23");
    } else if (lang == "Python2") {
        return compile_python2_7(conf);
    } else if (lang == "Python3") {
        return compile_python3(conf);
    } else if (lang == "Java8") {
        return compile_java(conf, "8");
    } else if (lang == "Java11") {
        return compile_java(conf, "11");
    } else if (lang == "Java17") {
        return compile_java(conf, "17");
    } else if (lang == "Java21") {
        return compile_java(conf, "21");
    } else if (lang == "Pascal") {
        return compile_pas(conf);
    } else {
        throw language_not_supported_error();
    }
}

int main(int argc, char **argv) {
    try {
        if (argc == 4 && std::string(argv[1]) == "--make-pch") {
            return make_pch(argv[2], argv[3]);
        }
        return compile(parse_args(argc, argv));
    } catch (std::exception &e) {
        std::cerr << e.what() << std::endl;
        return 1;
    }
}
