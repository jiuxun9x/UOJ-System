#pragma once

#include <argp.h>
#include <fcntl.h>
#include <seccomp.h>
#include <sys/prctl.h>
#include <sys/ptrace.h>
#include <sys/resource.h>
#include <sys/stat.h>
#include <sys/user.h>
#include <sys/wait.h>
#include <unistd.h>

#include <algorithm>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <fstream>
#include <iostream>
#include <map>
#include <set>
#include <string>
#include <vector>

#include "uoj_run.h"

enum EX_CHECK_TYPE : unsigned {
    ECT_NONE = 0,
    ECT_CNT = 1,
    ECT_FILE_OP = 1 << 1,                      // it is a file operation
    ECT_END_AT = 1 << 2,                       // this file operation ends with "at" (e.g., openat)
    ECT_FILEAT_OP = ECT_FILE_OP | ECT_END_AT,  // it is a file operation ended with "at"
    ECT_FILE_W = 1 << 3,                       // intend to write
    ECT_FILE_R = 1 << 4,                       // intend to read
    ECT_FILE_S = 1 << 5,                       // intend to stat
    ECT_CHECK_OPEN_FLAGS =
        1 << 6,  // check flags to determine whether it is to read/write (for open and openat)
    ECT_FILE2_W = 1 << 7,        // intend to write (2nd file)
    ECT_FILE2_R = 1 << 8,        // intend to read  (2nd file)
    ECT_FILE2_S = 1 << 9,        // intend to stat  (2nd file)
    ECT_CLONE_THREAD = 1 << 10,  // for clone(). Check that clone is making a non-suspicious thread
    ECT_KILL_SIG0_ALLOWED = 1 << 11,  // forbid kill but killing with sig0 is allowed
};

struct syscall_info {
    EX_CHECK_TYPE extra_check;
    int max_cnt;
    bool should_soft_ban = false;
    bool is_kill = false;

    syscall_info() : extra_check(ECT_CNT), max_cnt(0) {}
    syscall_info(unsigned extra_check, int max_cnt) :
        extra_check((EX_CHECK_TYPE)extra_check), max_cnt(max_cnt) {}

    static syscall_info unlimited() {
        return syscall_info(ECT_NONE, -1);
    }

    static syscall_info count_based(int max_cnt) {
        return syscall_info(ECT_CNT, max_cnt);
    }

    static syscall_info with_extra_check(unsigned extra_check, int max_cnt = -1) {
        if (max_cnt != -1) {
            extra_check |= ECT_CNT;
        }
        return syscall_info(extra_check, max_cnt);
    }

    static syscall_info kill_type_syscall(unsigned extra_check = ECT_CNT, int max_cnt = 0) {
        if (max_cnt != -1) {
            extra_check |= ECT_CNT;
        }
        syscall_info res(extra_check, max_cnt);
        res.is_kill = true;
        return res;
    }

    static syscall_info soft_ban() {
        syscall_info res(ECT_CNT, 0);
        res.should_soft_ban = true;
        return res;
    }
};

#ifndef __x86_64__
#error only x86-64 is supported!
#endif

/*
 * a mask that tells seccomp that it should SCMP_ACT_ERRNO(no)
 * when syscall #(mask | no) is called
 * used to implement SCMP_ACT_ERRNO(no) using ptrace:
 *     std::set the syscall number to mask | no;
 *     PTRACE_CONT
 *     seccomp performs SCMP_ACT_ERRNO(no)
 */
const int SYSCALL_SOFT_BAN_MASK = 996 << 18;

std::vector<int> supported_soft_ban_errno_list = {
    ENOENT,  // No such file or directory
    EPERM,   // Operation not permitted
    EACCES,  // Permission denied
};

std::set<std::string> available_program_type_set = {"default", "python2", "python3", "java",
                                                    "compiler"};

/*
 * folder program: the program to run is a folder, not a single regular file
 */
std::set<std::string> folder_program_type_set = {"java"};

std::map<std::string, std::vector<std::pair<int, syscall_info>>> allowed_syscall_list = {
    {"default",
     {
         {__NR_read, syscall_info::unlimited()},
         {__NR_pread64, syscall_info::unlimited()},
         {__NR_write, syscall_info::unlimited()},
         {__NR_pwrite64, syscall_info::unlimited()},
         {__NR_readv, syscall_info::unlimited()},
         {__NR_writev, syscall_info::unlimited()},
         {__NR_preadv, syscall_info::unlimited()},
         {__NR_pwritev, syscall_info::unlimited()},
         {__NR_sendfile, syscall_info::unlimited()},

         {__NR_close, syscall_info::unlimited()},
         {__NR_fstat, syscall_info::unlimited()},
         {__NR_fstatfs, syscall_info::unlimited()},
         {__NR_lseek, syscall_info::unlimited()},
         {__NR_dup, syscall_info::unlimited()},
         {__NR_dup2, syscall_info::unlimited()},
         {__NR_dup3, syscall_info::unlimited()},
         {__NR_ioctl, syscall_info::unlimited()},
         {__NR_fcntl, syscall_info::unlimited()},

         {__NR_gettid, syscall_info::unlimited()},
         {__NR_getpid, syscall_info::unlimited()},

         {__NR_mmap, syscall_info::unlimited()},
         {__NR_mprotect, syscall_info::unlimited()},
         {__NR_munmap, syscall_info::unlimited()},
         {__NR_brk, syscall_info::unlimited()},
         {__NR_mremap, syscall_info::unlimited()},
         {__NR_msync, syscall_info::unlimited()},
         {__NR_mincore, syscall_info::unlimited()},
         {__NR_madvise, syscall_info::unlimited()},

         {__NR_rt_sigaction, syscall_info::unlimited()},
         {__NR_rt_sigprocmask, syscall_info::unlimited()},
         {__NR_rt_sigreturn, syscall_info::unlimited()},
         {__NR_rt_sigpending, syscall_info::unlimited()},
         {__NR_sigaltstack, syscall_info::unlimited()},

         {__NR_getcwd, syscall_info::unlimited()},
         {__NR_uname, syscall_info::unlimited()},

         {__NR_exit, syscall_info::unlimited()},
         {__NR_exit_group, syscall_info::unlimited()},

         {__NR_arch_prctl, syscall_info::unlimited()},

         {__NR_getrusage, syscall_info::unlimited()},
         {__NR_getrlimit, syscall_info::unlimited()},

         {__NR_gettimeofday, syscall_info::unlimited()},
         {__NR_times, syscall_info::unlimited()},
         {__NR_time, syscall_info::unlimited()},
         {__NR_clock_gettime, syscall_info::unlimited()},
         {__NR_clock_getres, syscall_info::unlimited()},

         {__NR_restart_syscall, syscall_info::unlimited()},

         // for startup
         {__NR_setitimer, syscall_info::count_based(1)},
         {__NR_execve, syscall_info::count_based(1)},
         {__NR_set_robust_list, syscall_info::unlimited()},

         {__NR_set_tid_address, syscall_info::count_based(1)},
         {__NR_rseq, syscall_info::count_based(1)},

         // need to check file permissions
         {__NR_open, syscall_info::with_extra_check(ECT_FILE_OP | ECT_CHECK_OPEN_FLAGS)},
         {__NR_openat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_CHECK_OPEN_FLAGS)},
         {__NR_readlink, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_S)},
         {__NR_readlinkat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_S)},
         {__NR_access, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_R)},
         {__NR_faccessat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_R)},
         {__NR_faccessat2, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_R)},
         {__NR_stat, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_S)},
         {__NR_statfs, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_S)},
         {__NR_lstat, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_S)},
         {__NR_newfstatat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_S)},
         {__NR_statx, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_S)},

         // kill could be DGS or RE
         {__NR_kill, syscall_info::kill_type_syscall()},
         {__NR_tkill, syscall_info::kill_type_syscall()},
         {__NR_tgkill, syscall_info::kill_type_syscall()},

         // for python
         {__NR_prlimit64, syscall_info::soft_ban()},

         // for python and java
         {__NR_sysinfo, syscall_info::unlimited()},

         // python3 uses this call to generate random numbers
         // for fairness, all types of programs can use this call
         {__NR_getrandom, syscall_info::unlimited()},

         // futex
         {__NR_futex, syscall_info::unlimited()},

         // some python library uses epoll (e.g., z3-solver)
         {__NR_epoll_create, syscall_info::unlimited()},
         {__NR_epoll_create1, syscall_info::unlimited()},
         {__NR_epoll_ctl, syscall_info::unlimited()},
         {__NR_epoll_wait, syscall_info::unlimited()},
         {__NR_epoll_pwait, syscall_info::unlimited()},

         // for java
         {__NR_geteuid, syscall_info::unlimited()},
         {__NR_getuid, syscall_info::unlimited()},
         {__NR_setrlimit, syscall_info::soft_ban()},
         {__NR_socket, syscall_info::soft_ban()},
         {__NR_connect, syscall_info::soft_ban()},
     }},

    {"allow_proc",
     {
         {__NR_clone, syscall_info::unlimited()},
         {__NR_clone3, syscall_info::unlimited()},
         {__NR_fork, syscall_info::unlimited()},
         {__NR_vfork, syscall_info::unlimited()},
         {__NR_nanosleep, syscall_info::unlimited()},
         {__NR_clock_nanosleep, syscall_info::unlimited()},
         {__NR_wait4, syscall_info::unlimited()},

         {__NR_execve, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_R)},
     }},

    // A program that may change the files in a folder it may write to: a checker that keeps
    // what it needs between the passes of a test, and removes what it no longer needs. Every
    // one of these is checked against the files the program may write.
    {"allow_file_ops",
     {
         {__NR_unlink, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W)},
         {__NR_unlinkat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_W)},
         {__NR_rename, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W | ECT_FILE2_W)},
         {__NR_renameat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_W | ECT_FILE2_W)},
         {__NR_renameat2, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_W | ECT_FILE2_W)},
         {__NR_mkdir, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W)},
         {__NR_mkdirat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_W)},
         {__NR_rmdir, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W)},
         {__NR_truncate, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W)},
         {__NR_ftruncate, syscall_info::unlimited()},
         {__NR_getdents, syscall_info::unlimited()},
         {__NR_getdents64, syscall_info::unlimited()},
     }},

    {"python2",
     {
         {__NR_getdents, syscall_info::unlimited()},
         {__NR_getdents64, syscall_info::unlimited()},
     }},

    {"python3",
     {
         {__NR_getdents, syscall_info::unlimited()},
         {__NR_getdents64, syscall_info::unlimited()},
     }},

    {"java",
     {
         {__NR_clone, syscall_info::with_extra_check(ECT_CLONE_THREAD, 16)},
         {__NR_clone3, syscall_info::with_extra_check(ECT_CLONE_THREAD, 16)},
         {__NR_rseq, syscall_info::unlimited()},
         {__NR_prctl, syscall_info::unlimited()},      // TODO: add extra checks for prctl
         {__NR_prlimit64, syscall_info::unlimited()},  // TODO: add extra checks for
                                                       // prlimit64

         {__NR_getdents, syscall_info::unlimited()},
         {__NR_getdents64, syscall_info::unlimited()},

         {__NR_sched_getaffinity, syscall_info::unlimited()},
         {__NR_sched_yield, syscall_info::unlimited()},

         {__NR_nanosleep, syscall_info::unlimited()},
         {__NR_clock_nanosleep, syscall_info::unlimited()},
     }},

    {"compiler",
     {
         {__NR_set_tid_address, syscall_info::unlimited()},
         {__NR_rseq, syscall_info::unlimited()},

         {__NR_clone, syscall_info::unlimited()},
         {__NR_clone3, syscall_info::unlimited()},
         {__NR_fork, syscall_info::unlimited()},
         {__NR_vfork, syscall_info::unlimited()},
         {__NR_nanosleep, syscall_info::unlimited()},
         {__NR_clock_nanosleep, syscall_info::unlimited()},
         {__NR_wait4, syscall_info::unlimited()},

         {__NR_geteuid, syscall_info::unlimited()},
         {__NR_getuid, syscall_info::unlimited()},
         {__NR_getgid, syscall_info::unlimited()},
         {__NR_getegid, syscall_info::unlimited()},
         {__NR_getppid, syscall_info::unlimited()},
         {__NR_setresuid, syscall_info::unlimited()},
         {__NR_setresgid, syscall_info::unlimited()},

         {__NR_setrlimit, syscall_info::unlimited()},
         {__NR_prlimit64, syscall_info::unlimited()},
         {__NR_prctl, syscall_info::unlimited()},

         {__NR_pipe, syscall_info::unlimited()},
         {__NR_pipe2, syscall_info::unlimited()},

         // for java... we have no choice
         {__NR_socketpair, syscall_info::unlimited()},
         {__NR_socket, syscall_info::unlimited()},
         {__NR_getsockname, syscall_info::unlimited()},
         {__NR_setsockopt, syscall_info::unlimited()},
         {__NR_connect, syscall_info::unlimited()},
         {__NR_sendto, syscall_info::unlimited()},
         {__NR_poll, syscall_info::unlimited()},
         {__NR_recvmsg, syscall_info::unlimited()},
         {__NR_sysinfo, syscall_info::unlimited()},

         {__NR_umask, syscall_info::unlimited()},
         {__NR_getdents, syscall_info::unlimited()},
         {__NR_getdents64, syscall_info::unlimited()},

         {__NR_chdir, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_S)},
         {__NR_fchdir, syscall_info::unlimited()},

         {__NR_execve, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_R)},
         {__NR_execveat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_R)},

         {__NR_truncate, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W)},
         {__NR_ftruncate, syscall_info::unlimited()},

         {__NR_chmod, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W)},
         {__NR_fchmodat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_W)},
         {__NR_fchmod, syscall_info::unlimited()},

         {__NR_rename, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W | ECT_FILE2_W)},
         {__NR_renameat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_W | ECT_FILE2_W)},
         {__NR_renameat2, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_W | ECT_FILE2_W)},

         {__NR_unlink, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W)},
         {__NR_unlinkat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_W)},

         {__NR_mkdir, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W)},
         {__NR_mkdirat, syscall_info::with_extra_check(ECT_FILEAT_OP | ECT_FILE_W)},

         {__NR_rmdir, syscall_info::with_extra_check(ECT_FILE_OP | ECT_FILE_W)},

         {__NR_fadvise64, syscall_info::unlimited()},

         {__NR_sched_getaffinity, syscall_info::unlimited()},
         {__NR_sched_yield, syscall_info::unlimited()},

         {__NR_kill, syscall_info::kill_type_syscall(ECT_KILL_SIG0_ALLOWED, -1)},
         {__NR_tkill, syscall_info::kill_type_syscall(ECT_KILL_SIG0_ALLOWED, -1)},
         {__NR_tgkill, syscall_info::kill_type_syscall(ECT_KILL_SIG0_ALLOWED, -1)},
     }},
};

std::map<std::string, std::vector<std::string>> soft_ban_file_name_list = {
    {"default",
     {
         "/dev/tty",
         "/dev/pts/",

         // for java and javac...
         "/etc/nsswitch.conf",
         "/etc/passwd",
     }}};

std::map<std::string, std::vector<std::string>> statable_file_name_list = {
    {"default", {}},

    {"python2",
     {
         "/usr/",
         "/usr/bin/",
         "/usr/lib/",
     }},

    {"python3",
     {
         "/usr/",
         "/usr/bin/",
         "/usr/lib/",
         "/etc/python" UOJ_PYTHON3_VERSION "/",
     }},

    {"java",
     {
         "system_root",
         "/tmp/",
     }},

    {"compiler",
     {
         "/*",
         "/boot/",
     }},
};

std::map<std::string, std::vector<std::string>> readable_file_name_list = {
    {"default",
     {
         "/lib/x86_64-linux-gnu/", "/usr/lib/x86_64-linux-gnu/", "/usr/lib/locale/",
         "/usr/share/zoneinfo/", "/etc/ld.so.nohwcap", "/etc/ld.so.preload", "/etc/ld.so.cache",
         "/etc/timezone", "/etc/localtime", "/etc/locale.alias", "/proc/self/", "/proc/*",
         "/dev/random", "/dev/urandom",
         "/sys/devices/system/cpu/",  // for java & some python libraries
         "/proc/sys/vm/",             // for java
     }},

    {"python2",
     {
         "/etc/python2.7/",
         "/usr/bin/python2.7",
         "/usr/lib/python2.7/",
         "/usr/bin/lib/python2.7/",
         "/usr/local/lib/python2.7/",
         "/usr/lib/pymodules/python2.7/",
         "/usr/bin/Modules/",
         "/usr/bin/pybuilddir.txt",
     }},

    {"python3",
     {
         "/etc/python/" UOJ_PYTHON3_VERSION,
         "/usr/bin/python" UOJ_PYTHON3_VERSION,
         "/usr/lib/python" UOJ_PYTHON3_VERSION "/",
         "/usr/lib/python3/dist-packages/",
         "/usr/bin/lib/python" UOJ_PYTHON3_VERSION "/",
         "/usr/local/lib/python" UOJ_PYTHON3_VERSION "/",
         "/usr/bin/pyvenv.cfg",
         "/usr/pyvenv.cfg",
         "/usr/bin/Modules/",
         "/usr/bin/pybuilddir.txt",
         "/usr/lib/dist-python",
     }},

    {"java",
     {
         UOJ_JDK "/",
         "/sys/fs/cgroup/",
         "/etc/java-" UOJ_JAVA_VERSION "-openjdk/",
         "/usr/share/java/",
         "/sys/kernel/mm/hugepages/",
         "/sys/kernel/mm/transparent_hugepage/",
     }},

    {"compiler",
     {
         "system_root",
         "/dev/",
         "/usr/",
         "/lib/",
         "/lib64/",
         "/bin/",
         "/sbin/",
         "/sys/fs/cgroup/",
         "/proc/",
         "/etc/timezone",
         "/etc/alternatives/",
         "/sys/kernel/mm/hugepages/",             // java
         "/sys/kernel/mm/transparent_hugepage/",  // java
         "/etc/python2.7/",
         "/etc/python" UOJ_PYTHON3_VERSION "/",
         "/etc/fpc.cfg",
         "/etc/fpc-" UOJ_FPC_VERSION ".cfg",
         "/etc/java-" UOJ_JAVA_VERSION "-openjdk/",
     }}};

std::map<std::string, std::vector<std::string>> writable_file_name_list = {
    {"default",
     {
         "/dev/null",

         // for java
         "/proc/self/coredump_filter",
     }},

    {"compiler",
     {
         "/tmp/",
     }}};

const int N_SYSCALL = 440;
std::string syscall_name[N_SYSCALL] = {
    "read",
    "write",
    "open",
    "close",
    "stat",
    "fstat",
    "lstat",
    "poll",
    "lseek",
    "mmap",
    "mprotect",
    "munmap",
    "brk",
    "rt_sigaction",
    "rt_sigprocmask",
    "rt_sigreturn",
    "ioctl",
    "pread64",
    "pwrite64",
    "readv",
    "writev",
    "access",
    "pipe",
    "select",
    "sched_yield",
    "mremap",
    "msync",
    "mincore",
    "madvise",
    "shmget",
    "shmat",
    "shmctl",
    "dup",
    "dup2",
    "pause",
    "nanosleep",
    "getitimer",
    "alarm",
    "setitimer",
    "getpid",
    "sendfile",
    "socket",
    "connect",
    "accept",
    "sendto",
    "recvfrom",
    "sendmsg",
    "recvmsg",
    "shutdown",
    "bind",
    "listen",
    "getsockname",
    "getpeername",
    "socketpair",
    "setsockopt",
    "getsockopt",
    "clone",
    "fork",
    "vfork",
    "execve",
    "exit",
    "wait4",
    "kill",
    "uname",
    "semget",
    "semop",
    "semctl",
    "shmdt",
    "msgget",
    "msgsnd",
    "msgrcv",
    "msgctl",
    "fcntl",
    "flock",
    "fsync",
    "fdatasync",
    "truncate",
    "ftruncate",
    "getdents",
    "getcwd",
    "chdir",
    "fchdir",
    "rename",
    "mkdir",
    "rmdir",
    "creat",
    "link",
    "unlink",
    "symlink",
    "readlink",
    "chmod",
    "fchmod",
    "chown",
    "fchown",
    "lchown",
    "umask",
    "gettimeofday",
    "getrlimit",
    "getrusage",
    "sysinfo",
    "times",
    "ptrace",
    "getuid",
    "syslog",
    "getgid",
    "setuid",
    "setgid",
    "geteuid",
    "getegid",
    "setpgid",
    "getppid",
    "getpgrp",
    "setsid",
    "setreuid",
    "setregid",
    "getgroups",
    "setgroups",
    "setresuid",
    "getresuid",
    "setresgid",
    "getresgid",
    "getpgid",
    "setfsuid",
    "setfsgid",
    "getsid",
    "capget",
    "capset",
    "rt_sigpending",
    "rt_sigtimedwait",
    "rt_sigqueueinfo",
    "rt_sigsuspend",
    "sigaltstack",
    "utime",
    "mknod",
    "uselib",
    "personality",
    "ustat",
    "statfs",
    "fstatfs",
    "sysfs",
    "getpriority",
    "setpriority",
    "sched_setparam",
    "sched_getparam",
    "sched_setscheduler",
    "sched_getscheduler",
    "sched_get_priority_max",
    "sched_get_priority_min",
    "sched_rr_get_interval",
    "mlock",
    "munlock",
    "mlockall",
    "munlockall",
    "vhangup",
    "modify_ldt",
    "pivot_root",
    "_sysctl",
    "prctl",
    "arch_prctl",
    "adjtimex",
    "setrlimit",
    "chroot",
    "sync",
    "acct",
    "settimeofday",
    "mount",
    "umount2",
    "swapon",
    "swapoff",
    "reboot",
    "sethostname",
    "setdomainname",
    "iopl",
    "ioperm",
    "create_module",
    "init_module",
    "delete_module",
    "get_kernel_syms",
    "query_module",
    "quotactl",
    "nfsservctl",
    "getpmsg",
    "putpmsg",
    "afs_syscall",
    "tuxcall",
    "security",
    "gettid",
    "readahead",
    "setxattr",
    "lsetxattr",
    "fsetxattr",
    "getxattr",
    "lgetxattr",
    "fgetxattr",
    "listxattr",
    "llistxattr",
    "flistxattr",
    "removexattr",
    "lremovexattr",
    "fremovexattr",
    "tkill",
    "time",
    "futex",
    "sched_setaffinity",
    "sched_getaffinity",
    "set_thread_area",
    "io_setup",
    "io_destroy",
    "io_getevents",
    "io_submit",
    "io_cancel",
    "get_thread_area",
    "lookup_dcookie",
    "epoll_create",
    "epoll_ctl_old",
    "epoll_wait_old",
    "remap_file_pages",
    "getdents64",
    "set_tid_address",
    "restart_syscall",
    "semtimedop",
    "fadvise64",
    "timer_create",
    "timer_settime",
    "timer_gettime",
    "timer_getoverrun",
    "timer_delete",
    "clock_settime",
    "clock_gettime",
    "clock_getres",
    "clock_nanosleep",
    "exit_group",
    "epoll_wait",
    "epoll_ctl",
    "tgkill",
    "utimes",
    "vserver",
    "mbind",
    "set_mempolicy",
    "get_mempolicy",
    "mq_open",
    "mq_unlink",
    "mq_timedsend",
    "mq_timedreceive",
    "mq_notify",
    "mq_getsetattr",
    "kexec_load",
    "waitid",
    "add_key",
    "request_key",
    "keyctl",
    "ioprio_set",
    "ioprio_get",
    "inotify_init",
    "inotify_add_watch",
    "inotify_rm_watch",
    "migrate_pages",
    "openat",
    "mkdirat",
    "mknodat",
    "fchownat",
    "futimesat",
    "newfstatat",
    "unlinkat",
    "renameat",
    "linkat",
    "symlinkat",
    "readlinkat",
    "fchmodat",
    "faccessat",
    "pselect6",
    "ppoll",
    "unshare",
    "set_robust_list",
    "get_robust_list",
    "splice",
    "tee",
    "sync_file_range",
    "vmsplice",
    "move_pages",
    "utimensat",
    "epoll_pwait",
    "signalfd",
    "timerfd_create",
    "eventfd",
    "fallocate",
    "timerfd_settime",
    "timerfd_gettime",
    "accept4",
    "signalfd4",
    "eventfd2",
    "epoll_create1",
    "dup3",
    "pipe2",
    "inotify_init1",
    "preadv",
    "pwritev",
    "rt_tgsigqueueinfo",
    "perf_event_open",
    "recvmmsg",
    "fanotify_init",
    "fanotify_mark",
    "prlimit64",
    "name_to_handle_at",
    "open_by_handle_at",
    "clock_adjtime",
    "syncfs",
    "sendmmsg",
    "setns",
    "getcpu",
    "process_vm_readv",
    "process_vm_writev",
    "kcmp",
    "finit_module",
    "sched_setattr",
    "sched_getattr",
    "renameat2",
    "seccomp",
    "getrandom",
    "memfd_create",
    "kexec_file_load",
    "bpf",
    "execveat",
    "userfaultfd",
    "membarrier",
    "mlock2",
    "copy_file_range",
    "preadv2",
    "pwritev2",
    "pkey_mprotect",
    "pkey_alloc",
    "pkey_free",
    "statx",
    "io_pgetevents",
    "rseq",  // 334
    "?335",
    "?336",
    "?337",
    "?338",
    "?339",
    "?340",
    "?341",
    "?342",
    "?343",
    "?344",
    "?345",
    "?346",
    "?347",
    "?348",
    "?349",
    "?350",
    "?351",
    "?352",
    "?353",
    "?354",
    "?355",
    "?356",
    "?357",
    "?358",
    "?359",
    "?360",
    "?361",
    "?362",
    "?363",
    "?364",
    "?365",
    "?366",
    "?367",
    "?368",
    "?369",
    "?370",
    "?371",
    "?372",
    "?373",
    "?374",
    "?375",
    "?376",
    "?377",
    "?378",
    "?379",
    "?380",
    "?381",
    "?382",
    "?383",
    "?384",
    "?385",
    "?386",
    "?387",
    "?388",
    "?389",
    "?390",
    "?391",
    "?392",
    "?393",
    "?394",
    "?395",
    "?396",
    "?397",
    "?398",
    "?399",
    "?400",
    "?401",
    "?402",
    "?403",
    "?404",
    "?405",
    "?406",
    "?407",
    "?408",
    "?409",
    "?410",
    "?411",
    "?412",
    "?413",
    "?414",
    "?415",
    "?416",
    "?417",
    "?418",
    "?419",
    "?420",
    "?421",
    "?422",
    "?423",
    "?424",
    "?425",
    "?426",
    "?427",
    "?428",
    "?429",
    "?430",
    "?431",
    "?432",
    "?433",
    "?434",
    "clone3",
    "?436",
    "?437",
    "?438",
    "faccessat2",  // 439
};

typedef unsigned long long int reg_val_t;
#define REG_SYSCALL orig_rax
#define REG_RET rax
#define REG_ARG0 rdi
#define REG_ARG1 rsi
#define REG_ARG2 rdx
#define REG_ARG3 rcx

enum CHILD_PROC_FLAG : unsigned { CPF_STARTUP = 1u << 0, CPF_IGNORE_ONE_SIGSTOP = 1u << 2 };

struct rp_child_proc {
    pid_t pid;

    unsigned flags;

    struct user_regs_struct reg = {};
    int syscall = -1;
    std::string error;
    bool suspicious = false;
    bool try_to_create_new_process = false;

    void set_error_for_suspicious(const std::string &error);
    void set_error_for_kill();
    void soft_ban_syscall(int set_no);
    bool check_safe_syscall();
    bool check_file_permission(const std::string &op, const std::string &fn, char mode);
};

struct clone_args {
    std::uint64_t flags;        /* Flags bit mask */
    std::uint64_t pidfd;        /* Where to store PID file descriptor (int *) */
    std::uint64_t child_tid;    /* Where to store child TID, in child's memory (pid_t *) */
    std::uint64_t parent_tid;   /* Where to store child TID, in parent's memory (pid_t *) */
    std::uint64_t exit_signal;  /* Signal to deliver to parent on child termination */
    std::uint64_t stack;        /* Pointer to lowest byte of stack */
    std::uint64_t stack_size;   /* Size of stack */
    std::uint64_t tls;          /* Location of new TLS */
    std::uint64_t set_tid;      /* Pointer to a pid_t array (since Linux 5.5) */
    std::uint64_t set_tid_size; /* Number of elements in set_tid (since Linux 5.5) */
    std::uint64_t cgroup;       /* File descriptor for target cgroup of child (since Linux 5.7) */
};

void read_memory_from_addr(reg_val_t addr, pid_t pid, void *buf, size_t size) {
    uint8_t *ptr = (uint8_t *)buf;
    for (size_t i = 0; i < size; i += sizeof(reg_val_t)) {
        reg_val_t data = ptrace(PTRACE_PEEKDATA, pid, addr + i, NULL);
        size_t copy_size = std::min(sizeof(reg_val_t), size - i);
        memcpy(ptr + i, &data, copy_size);
    }
}

const size_t MAX_PATH_LEN = 512;
const uint64_t MAX_FD_ID = 1 << 20;

const std::string INVALID_PATH(PATH_MAX + 8, 'X');
const std::string EMPTY_PATH_AFTER_FD = "?empty_path_after_fd";

runp::config run_program_config;

std::set<std::string> writable_file_name_set;
std::set<std::string> readable_file_name_set;
std::set<std::string> statable_file_name_set;
std::set<std::string> soft_ban_file_name_set;

syscall_info syscall_info_set[N_SYSCALL];

pid_t get_tgid_from_pid(pid_t pid) {
    std::ifstream fin("/proc/" + std::to_string(pid) + "/status");
    std::string key;
    while (fin >> key) {
        if (key == "Tgid:") {
            pid_t tgid;
            if (fin >> tgid) {
                return tgid;
            } else {
                return -1;
            }
        }
    }
    return -1;
}

bool is_len_valid_path(const std::string &path) {
    return !path.empty() && path.size() <= MAX_PATH_LEN;
}

std::string path_or_len_invalid(const std::string &path) {
    return is_len_valid_path(path) ? path : INVALID_PATH;
}

std::string basename(const std::string &path) {
    if (!is_len_valid_path(path)) {
        return INVALID_PATH;
    }
    size_t p = path.rfind('/');
    if (p == std::string::npos) {
        return path;
    } else {
        return path.substr(p + 1);  // can be empty, e.g., path = "abc/"
    }
}
std::string dirname(const std::string &path) {
    if (!is_len_valid_path(path)) {
        return INVALID_PATH;
    }
    size_t p = path.rfind('/');
    if (p == std::string::npos) {
        return INVALID_PATH;
    } else {
        return path.substr(0, p);  // can be empty, e.g., path = "/abc"
    }
}
std::string realpath(const std::string &path) {
    if (!is_len_valid_path(path)) {
        return INVALID_PATH;
    }
    static char real[PATH_MAX + 1] = {};
    if (realpath(path.c_str(), real) == NULL) {
        return INVALID_PATH;
    }
    return path_or_len_invalid(real);
}
std::string realpath_for_write(const std::string &path) {
    std::string real = realpath(path);
    if (!is_len_valid_path(path)) {
        return INVALID_PATH;
    }

    std::string b = basename(path);
    if (!is_len_valid_path(b) || b == "." || b == "..") {
        return INVALID_PATH;
    }
    real = realpath(dirname(path));
    if (!is_len_valid_path(real)) {
        return INVALID_PATH;
    }
    return path_or_len_invalid(real + "/" + b);
}
std::string readlink(const std::string &path) {
    if (!is_len_valid_path(path)) {
        return INVALID_PATH;
    }
    static char buf[MAX_PATH_LEN + 1];
    ssize_t n = readlink(path.c_str(), buf, MAX_PATH_LEN + 1);
    if (n > (ssize_t)MAX_PATH_LEN) {
        return INVALID_PATH;
    } else {
        buf[n] = '\0';
        return path_or_len_invalid(buf);
    }
}
std::string getcwd() {
    char cwd[MAX_PATH_LEN + 1];
    if (getcwd(cwd, MAX_PATH_LEN) == NULL) {
        return INVALID_PATH;
    } else {
        return path_or_len_invalid(cwd);
    }
}
std::string getcwdp(pid_t pid) {
    return realpath("/proc/" + (pid == 0 ? "self" : std::to_string(pid)) + "/cwd");
}
std::string abspath(const std::string &path, pid_t pid, int fd = AT_FDCWD) {
    static int depth = 0;
    if (depth == 10 || !is_len_valid_path(path)) {
        return INVALID_PATH;
    }

    std::vector<std::string> lv;
    for (std::string cur = path; is_len_valid_path(cur); cur = dirname(cur)) {
        lv.push_back(basename(cur));
    }
    std::reverse(lv.begin(), lv.end());

    std::string pos;
    if (path[0] == '/') {
        pos = "/";
    } else if (fd == AT_FDCWD) {
        pos = getcwdp(pid);
    } else {
        depth++;
        pos = abspath("/proc/self/fd/" + std::to_string(fd), pid);
        depth--;
    }
    if (!is_len_valid_path(pos)) {
        return INVALID_PATH;
    }

    struct stat stat_buf;
    bool reachable = true;
    for (auto &v : lv) {
        if (reachable) {
            if (lstat(pos.c_str(), &stat_buf) < 0 || !S_ISDIR(stat_buf.st_mode)) {
                reachable = false;
            }
        }

        if (reachable) {
            if (v == ".") {
                continue;
            } else if (v == "..") {
                pos = dirname(pos);
                if (pos.empty()) {
                    pos = "/";
                }
                continue;
            }
        }

        if (v.empty()) {
            continue;
        }
        if (pos.back() != '/') {
            pos += '/';
        }
        pos += v;
        if (pos.size() > MAX_PATH_LEN) {
            return INVALID_PATH;
        }

        if (reachable) {
            std::string realpos;
            if (pos == "/proc/self") {
                realpos = "/proc/" + std::to_string(get_tgid_from_pid(pid));
            } else if (pos == "/proc/thread-self") {
                realpos =
                    "/proc/" + std::to_string(get_tgid_from_pid(pid)) + "/" + std::to_string(pid);
            } else {
                if (lstat(pos.c_str(), &stat_buf) < 0) {
                    reachable = false;
                    continue;
                }
                if (!S_ISLNK(stat_buf.st_mode)) {
                    continue;
                }
                realpos = readlink(pos);
                if (!is_len_valid_path(realpos)) {
                    return INVALID_PATH;
                }
                if (realpos[0] != '/') {
                    realpos = dirname(pos) + "/" + realpos;
                }
            }

            depth++;
            realpos = abspath(realpos, pid);
            depth--;
            if (!is_len_valid_path(realpos)) {
                return INVALID_PATH;
            }
            pos = realpos;
        }
    }

    return path_or_len_invalid(pos);
}
std::string getfdp(pid_t pid, int fd) {
    if (fd == AT_FDCWD) {
        return getcwdp(pid);
    } else {
        return abspath("/proc/self/fd/" + std::to_string(fd), pid);
    }
}

inline bool is_in_set_smart(std::string name, const std::set<std::string> &s) {
    if (name.size() > MAX_PATH_LEN) {
        return false;
    }
    if (s.count(name)) {
        return true;
    }
    int level;
    for (level = 0; !name.empty(); name = dirname(name), level++) {
        if (level == 1 && s.count(name + "/*")) {
            return true;
        }
        if (s.count(name + "/")) {
            return true;
        }
    }
    if (level == 1 && s.count("/*")) {
        return true;
    }
    if (s.count("/")) {
        return true;
    }
    return false;
}

inline bool is_writable_file(std::string name) {
    if (name == "/") {
        return writable_file_name_set.count("system_root");
    }
    return is_in_set_smart(name, writable_file_name_set);
}
inline bool is_readable_file(const std::string &name) {
    if (name == "/") {
        return readable_file_name_set.count("system_root");
    }
    return is_in_set_smart(name, readable_file_name_set);
}
inline bool is_statable_file(const std::string &name) {
    if (name == "/") {
        return statable_file_name_set.count("system_root");
    }
    return is_in_set_smart(name, statable_file_name_set);
}
inline bool is_soft_ban_file(const std::string &name) {
    if (name == "/") {
        return soft_ban_file_name_set.count("system_root");
    }
    return is_in_set_smart(name, soft_ban_file_name_set);
}

void add_file_permission(const std::string &file_name, char mode) {
    if (file_name.empty()) {
        return;
    }
    if (mode == 'w') {
        writable_file_name_set.insert(file_name);
    } else if (mode == 'r') {
        readable_file_name_set.insert(file_name);
    } else if (mode == 's') {
        statable_file_name_set.insert(file_name);
    }
    if (file_name == "system_root") {
        return;
    }
    for (std::string name = dirname(file_name); !name.empty(); name = dirname(name)) {
        statable_file_name_set.insert(name);
    }
}

void init_conf() {
    const runp::config &config = run_program_config;
    add_file_permission(config.work_path, 'r');
    add_file_permission(config.work_path + "/", 's');
    if (folder_program_type_set.count(config.type)) {
        add_file_permission(realpath(config.program_name) + "/", 'r');
    } else {
        add_file_permission(realpath(config.program_name), 'r');
    }

    std::vector<std::string> loads;
    loads.push_back("default");
    if (config.allow_proc) {
        loads.push_back("allow_proc");
    }
    if (config.allow_file_ops) {
        loads.push_back("allow_file_ops");
    }
    if (config.type != "default") {
        loads.push_back(config.type);
    }

    for (std::string type : loads) {
        if (allowed_syscall_list.count(type)) {
            for (const auto &kv : allowed_syscall_list[type]) {
                syscall_info_set[kv.first] = kv.second;
            }
        }
        if (soft_ban_file_name_list.count(type)) {
            for (const auto &name : soft_ban_file_name_list[type]) {
                soft_ban_file_name_set.insert(name);
            }
        }
        if (statable_file_name_list.count(type)) {
            for (const auto &name : statable_file_name_list[type]) {
                add_file_permission(name, 's');
            }
        }
        if (readable_file_name_list.count(type)) {
            for (const auto &name : readable_file_name_list[type]) {
                add_file_permission(name, 'r');
            }
        }
        if (writable_file_name_list.count(type)) {
            for (const auto &name : writable_file_name_list[type]) {
                add_file_permission(name, 'w');
            }
        }
    }

    for (const auto &name : config.readable_file_names) {
        add_file_permission(name, 'r');
    }
    for (const auto &name : config.writable_file_names) {
        add_file_permission(name, 'w');
    }

    if (config.type == "python2" || config.type == "python3") {
        soft_ban_file_name_set.insert(dirname(realpath(config.program_name)) + "/__pycode__/");
    } else if (config.type == "compiler") {
        add_file_permission(config.work_path + "/", 'w');
    }

    readable_file_name_set.insert(writable_file_name_set.begin(), writable_file_name_set.end());
    statable_file_name_set.insert(readable_file_name_set.begin(), readable_file_name_set.end());
}

std::string read_string_from_addr(reg_val_t addr, pid_t pid) {
    int max_len = MAX_PATH_LEN + sizeof(reg_val_t);
    char res[max_len + 1], *ptr = res;
    while (ptr != res + max_len) {
        *(reg_val_t *)ptr = ptrace(PTRACE_PEEKDATA, pid, addr, NULL);
        for (size_t i = 0; i < sizeof(reg_val_t); i++, ptr++, addr++) {
            if (*ptr == 0) {
                return res;
            }
        }
    }
    res[max_len] = 0;
    return res;
}
std::string read_abspath_from_addr(reg_val_t addr, pid_t pid) {
    std::string p = read_string_from_addr(addr, pid);
    std::string a = abspath(p, pid);
    if (run_program_config.need_show_trace_details) {
        fprintf(stderr, "path     : %s -> %s\n", p.c_str(),
                is_len_valid_path(a) ? a.c_str() : "INVALID!");
    }
    return a;
}
std::string read_abspath_from_fd_and_addr(reg_val_t fd, reg_val_t addr, pid_t pid) {
    if (fd > MAX_FD_ID && (int)fd != AT_FDCWD) {
        return INVALID_PATH;
    }
    std::string p = read_string_from_addr(addr, pid);
    std::string a;
    if (p.empty()) {
        // this case is tricky
        // if p is empty, in the following cases, Linux will understand the path as the path of fd:
        // newfstatat + AT_EMPTY_PATH, linkat + AT_EMPTY_PATH, execveat + AT_EMPTY_PATH, readlinkat
        // otherwise, the syscall will return with an error
        // since fd is already opened, the program should have the permission to do the things
        // listed above (no read -> write conversion, no deletion, no chmod, etc.) we just report
        // this special case. the program will skip the permission check later
        a = EMPTY_PATH_AFTER_FD;
    } else {
        a = abspath(p, pid, (int)fd);
    }
    if (run_program_config.need_show_trace_details) {
        fprintf(stderr, "path     : %d, %s -> %s\n", (int)fd, p.c_str(),
                is_len_valid_path(a) ? a.c_str() : "INVALID!");
    }
    return a;
}

bool set_seccomp_bpf() {
    scmp_filter_ctx ctx = seccomp_init(SCMP_ACT_TRACE(0));
    if (!ctx) {
        return false;
    }

    try {
        for (int no : supported_soft_ban_errno_list) {
            if (seccomp_rule_add(ctx, SCMP_ACT_ERRNO(no), SYSCALL_SOFT_BAN_MASK | no, 0) < 0) {
                throw std::system_error();
            }
        }

        for (int i = 0; i < N_SYSCALL; i++) {
            if (syscall_info_set[i].extra_check == ECT_NONE) {
                if (syscall_info_set[i].should_soft_ban) {
                    if (seccomp_rule_add(ctx, SCMP_ACT_ERRNO(EPERM), i, 0) < 0) {
                        throw std::system_error();
                    }
                } else {
                    if (seccomp_rule_add(ctx, SCMP_ACT_ALLOW, i, 0) < 0) {
                        throw std::system_error();
                    }
                }
            }
        }
        seccomp_load(ctx);
    } catch (std::system_error &e) {
        seccomp_release(ctx);
        return false;
    }
    seccomp_release(ctx);
    return true;
}

void rp_child_proc::set_error_for_suspicious(const std::string &error) {
    this->suspicious = true;
    this->error = "suspicious system call invoked: " + error;
}

void rp_child_proc::set_error_for_kill() {
    this->suspicious = false;
    reg_val_t sig = this->syscall == __NR_tgkill ? this->reg.REG_ARG2 : this->reg.REG_ARG1;
    this->error = "signal sent via " + syscall_name[this->syscall] + ": ";
    if (sig != (unsigned)sig) {
        this->error += "Unknown signal " + std::to_string(sig);
    } else {
        this->error += strsignal((int)sig);
    }
}

void rp_child_proc::soft_ban_syscall(int set_no = EPERM) {
    this->reg.REG_SYSCALL = SYSCALL_SOFT_BAN_MASK | set_no;
    ptrace(PTRACE_SETREGS, pid, NULL, &this->reg);
}

bool rp_child_proc::check_file_permission(const std::string &op, const std::string &fn, char mode) {
    std::string real_fn;
    if (!fn.empty()) {
        real_fn = mode == 'w' ? realpath_for_write(fn) : realpath(fn);
    }
    if (!is_len_valid_path(real_fn)) {
        // path invalid or file not found
        // ban this syscall softly
        this->soft_ban_syscall(ENOENT);
        return true;
    }

    std::string path_proc_self = "/proc/" + std::to_string(get_tgid_from_pid(this->pid));
    if (real_fn.compare(0, path_proc_self.size() + 1, path_proc_self + "/") == 0) {
        real_fn = "/proc/self" + real_fn.substr(path_proc_self.size());
    } else if (real_fn == path_proc_self) {
        real_fn = "/proc/self";
    }

    bool ok;
    switch (mode) {
        case 'w':
            ok = is_writable_file(real_fn);
            break;
        case 'r':
            ok = is_readable_file(real_fn);
            break;
        case 's':
            ok = is_statable_file(real_fn);
            break;
        default:
            ok = false;
            break;
    }

    if (ok) {
        return true;
    }

    if (run_program_config.need_show_trace_details) {
        fprintf(stderr, "check file permission %s : %s\n", op.c_str(), real_fn.c_str());
        fprintf(stderr, "[readable]\n");
        for (auto s : readable_file_name_set) {
            std::cerr << s << '\n';
        }
        fprintf(stderr, "[writable]\n");
        for (auto s : writable_file_name_set) {
            std::cerr << s << '\n';
        }
    }

    if (is_soft_ban_file(real_fn)) {
        this->soft_ban_syscall(EACCES);
        return true;
    } else {
        this->set_error_for_suspicious("intended to access a file without permission: " + op);
        return false;
    }
}

bool rp_child_proc::check_safe_syscall() {
    ptrace(PTRACE_GETREGS, pid, NULL, &reg);

    int cur_instruction = ptrace(PTRACE_PEEKTEXT, pid, reg.rip - 2, NULL) & 0xffff;
    if (cur_instruction != 0x050f) {
        if (run_program_config.need_show_trace_details) {
            fprintf(stderr, "informal syscall  %d\n", cur_instruction);
        }
        this->set_error_for_suspicious("incorrect opcode " + std::to_string(cur_instruction));
        return false;
    }

    if (0 > (long long int)reg.REG_SYSCALL || (long long int)reg.REG_SYSCALL >= N_SYSCALL) {
        this->set_error_for_suspicious(std::to_string(reg.REG_SYSCALL));
        return false;
    }
    syscall = (int)reg.REG_SYSCALL;
    if (run_program_config.need_show_trace_details) {
        fprintf(stderr, "[syscall %s]\n", syscall_name[syscall].c_str());
    }
    this->try_to_create_new_process = syscall == __NR_fork || syscall == __NR_clone
                                      || syscall == __NR_clone3 || syscall == __NR_vfork;

    auto &cursc = syscall_info_set[syscall];

    if (cursc.extra_check & ECT_CNT) {
        if (cursc.max_cnt == 0) {
            if (cursc.should_soft_ban) {
                this->soft_ban_syscall();
                return true;
            } else {
                if (cursc.is_kill) {
                    this->set_error_for_kill();
                } else {
                    this->set_error_for_suspicious(syscall_name[syscall]);
                }
                return false;
            }
        }
        cursc.max_cnt--;
    }

    if (cursc.extra_check & ECT_KILL_SIG0_ALLOWED) {
        reg_val_t sig = this->syscall == __NR_tgkill ? this->reg.REG_ARG2 : this->reg.REG_ARG1;
        if (sig != 0) {
            this->set_error_for_kill();
            return false;
        }
    }

    if (cursc.extra_check & ECT_FILE_OP) {
        std::string fn;
        if (cursc.extra_check & ECT_END_AT) {
            fn = read_abspath_from_fd_and_addr(reg.REG_ARG0, reg.REG_ARG1, pid);
        } else {
            fn = read_abspath_from_addr(reg.REG_ARG0, pid);
        }

        std::string textop = syscall_name[syscall];
        char mode = 'w';
        if (cursc.extra_check & ECT_CHECK_OPEN_FLAGS) {
            reg_val_t flags = cursc.extra_check & ECT_END_AT ? reg.REG_ARG2 : reg.REG_ARG1;
            switch (flags & O_ACCMODE) {
                case O_RDONLY:
                    if ((flags & O_CREAT) == 0 && (flags & O_EXCL) == 0 && (flags & O_TRUNC) == 0) {
                        textop += " (for read)";
                        mode = 'r';
                    } else {
                        textop += " (for read & write)";
                    }
                    break;
                case O_WRONLY:
                    textop += " (for write)";
                    break;
                case O_RDWR:
                    textop += " (for read & write)";
                    break;
                default:
                    textop += " (with invalid flags)";
                    break;
            }
        } else if (cursc.extra_check & ECT_FILE_S) {
            mode = 's';
        } else if (cursc.extra_check & ECT_FILE_R) {
            mode = 'r';
        } else if (cursc.extra_check & ECT_FILE_W) {
            mode = 'w';
        }  // else, error!

        if (run_program_config.need_show_trace_details) {
            fprintf(stderr, "%-8s : %s\n", syscall_name[syscall].c_str(), fn.c_str());
        }
        if (fn != EMPTY_PATH_AFTER_FD && !check_file_permission(textop, fn, mode)) {
            return false;
        }

        if (cursc.extra_check & ECT_FILE2_S) {
            mode = 's';
        } else if (cursc.extra_check & ECT_FILE2_R) {
            mode = 'r';
        } else if (cursc.extra_check & ECT_FILE2_W) {
            mode = 'w';
        } else {
            mode = '?';
        }
        if (mode != '?') {
            if (cursc.extra_check & ECT_END_AT) {
                fn = read_abspath_from_fd_and_addr(reg.REG_ARG2, reg.REG_ARG3, pid);
            } else {
                fn = read_abspath_from_addr(reg.REG_ARG1, pid);
            }
            if (run_program_config.need_show_trace_details) {
                fprintf(stderr, "%-8s : %s\n", syscall_name[syscall].c_str(), fn.c_str());
            }
            if (fn != EMPTY_PATH_AFTER_FD && !check_file_permission(textop, fn, mode)) {
                return false;
            }
        }
    }

    if (cursc.extra_check & ECT_CLONE_THREAD) {
        reg_val_t flags;
        if (syscall == __NR_clone) {
            flags = reg.REG_ARG0;
        } else if (syscall == __NR_clone3) {
            struct clone_args args;
            read_memory_from_addr(reg.REG_ARG0, pid, &args, sizeof(args));
            flags = args.flags;
        } else {
            // Should not happen if syscalls are configured correctly
            this->set_error_for_suspicious("clone/clone3 check on non-clone syscall");
            return false;
        }

        if (!(flags & CLONE_THREAD)) {
            this->set_error_for_suspicious("intended to create a new process");
            return false;
        }
        reg_val_t standard_flags = CLONE_VM | CLONE_FS | CLONE_FILES | CLONE_SIGHAND | CLONE_SYSVSEM
                                   | CLONE_SETTLS | CLONE_PARENT_SETTID | CLONE_CHILD_CLEARTID;

        if ((flags & standard_flags) != standard_flags) {
            this->set_error_for_suspicious("intended to create a non-standard thread");
            return false;
        }
    }

    return true;
}
