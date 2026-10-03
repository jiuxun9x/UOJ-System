INCLUDE_PATH = include
CXXFLAGS = -I./include -O2 --std=c++17 -Wall -lstdc++fs

EXE_CHECKER = \
	builtin/checker/bcmp \
	builtin/checker/acmp \
	builtin/checker/caseicmp \
	builtin/checker/casencmp \
	builtin/checker/casewcmp \
	builtin/checker/dcmp \
	builtin/checker/fcmp \
	builtin/checker/hcmp \
	builtin/checker/icmp \
	builtin/checker/lcmp \
	builtin/checker/ncmp \
	builtin/checker/rcmp \
	builtin/checker/rcmp4 \
	builtin/checker/rcmp6 \
	builtin/checker/rcmp9 \
	builtin/checker/rncmp \
	builtin/checker/uncmp \
	builtin/checker/wcmp \
	builtin/checker/yesno

EXE = main_judger \
	run/formatter \
	run/run_program \
	run/run_interaction \
	run/compile \
	builtin/judger/judger \
	$(EXE_CHECKER)

TESTS = tests/test_conf_run_limit_v1 tests/test_conf_run_limit_v2

all: $(EXE)

% : %.cpp
	$(CXX) $(CXXFLAGS) $(EXTRA_CXXFLAGS) $< -o $@

run/formatter       : include/testlib.h
run/compile         : include/uoj_run.h

run/run_program     : run/run_program.cpp run/run_program_sandbox.h include/uoj_run.h
	$(CXX) $(CXXFLAGS) $< -o $@ -lseccomp -pthread

run/run_interaction : run/run_interaction.cpp include/uoj_run.h
	$(CXX) $(CXXFLAGS) $< -o $@ -pthread

builtin/judger/judger: include/*.h
main_judger: include/*.h

tests/test_conf_run_limit_v1: tests/test_conf_run_limit.cpp include/*.h
	$(CXX) $(CXXFLAGS) $< -o $@

tests/test_conf_run_limit_v2: tests/test_conf_run_limit.cpp include/*.h
	$(CXX) $(CXXFLAGS) -DUOJ_TEST_JUDGER_V2 $< -o $@

test: $(TESTS)
	@for t in $(TESTS); do ./$$t || exit 1; done

$(EXE_CHECKER): include/testlib.h

clean:
	rm -f $(EXE) $(TESTS)

.PHONY: all test clean
