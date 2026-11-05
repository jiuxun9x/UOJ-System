// Regression tests for when run/compile uses a precompiled <bits/stdc++.h>.
//
// Only a program whose first line (but empty lines and comments) is #include <bits/stdc++.h>
// may be compiled with the precompiled header: anything before it, such as a #define or a
// #pragma, could change what the header means.

#include <unistd.h>

#define main compile_main
#include "../run/compile.cpp"
#undef main

static int n_checks = 0;
static int n_failures = 0;

static std::string write_source(const std::string &content) {
    static int n = 0;
    std::string name = "/tmp/uoj_test_compile_pch_" + std::to_string(getpid()) + "_" + std::to_string(n++) + ".cpp";
    std::ofstream(name, std::ios::binary) << content;
    return name;
}

static void check(const std::string &content, bool expected) {
    n_checks++;
    std::string name = write_source(content);
    bool actual = starts_with_bits_include(name);
    std::remove(name.c_str());
    if (actual != expected) {
        n_failures++;
        std::cerr << "starts_with_bits_include() is " << actual << ", expected " << expected << ", for:\n"
                  << content << "\n----" << std::endl;
    }
}

int main() {
    // what may be compiled with the precompiled header
    check("#include <bits/stdc++.h>\nint main() {}\n", true);
    check("#include<bits/stdc++.h>\r\nint main() {}\r\n", true);
    check("\xEF\xBB\xBF#include <bits/stdc++.h>\nint main() {}\n", true);
    check("// A+B\n/* by me,\n   today */\n\n  #  include <bits/stdc++.h>  // everything\nusing namespace std;\n", true);
    check("/* x */ #include <bits/stdc++.h>\n", true);

    // what is compiled the ordinary way
    check("#define int long long\n#include <bits/stdc++.h>\n", false);
    check("#pragma GCC optimize(\"O3\")\n#include <bits/stdc++.h>\n", false);
    check("#define _GLIBCXX_DEBUG\n#include <bits/stdc++.h>\n", false);
    check("#include <cstdio>\n#include <bits/stdc++.h>\n", false);
    check("#include \"bits/stdc++.h\"\n", false);
    check("#include <bits/stdc++.h> /* why not */\n", false);
    check("#include <bits/stdc++.h> // a \\\n", false);
    check("using namespace std;\n#include <bits/stdc++.h>\n", false);
    // the comment goes on in the next line, which is not an #include then
    check("// a comment \\\n#include <bits/stdc++.h>\n", false);
    check("// a comment ?\?/\n#include <bits/stdc++.h>\n", false);
    // what can not be read to the end of the line
    check("#include <bits/stdc++.h>", false);
    check("/* never closed\n#include <bits/stdc++.h>\n", false);
    check("", false);
    check(std::string(70000, ' ') + "\n#include <bits/stdc++.h>\n", false);

    // nor where it is not asked for, or the program has a part of another source
    {
        std::string name = write_source("#include <bits/stdc++.h>\n");
        compile_config conf;
        conf.src = name;
        conf.pch = false;
        n_checks++;
        if (pch_dir_for(conf, "c++17") != "") {
            n_failures++;
            std::cerr << "a precompiled header is used with --no-pch" << std::endl;
        }
        conf.pch = true;
        conf.implementer = "implementer";
        n_checks++;
        if (pch_dir_for(conf, "c++17") != "") {
            n_failures++;
            std::cerr << "a precompiled header is used with an implementer" << std::endl;
        }
        std::remove(name.c_str());
    }

    if (n_failures != 0) {
        std::cerr << "compile.cpp: " << n_failures << " of " << n_checks << " checks failed" << std::endl;
        return 1;
    }
    std::cout << "compile.cpp: all " << n_checks << " precompiled header checks passed" << std::endl;
    return 0;
}
