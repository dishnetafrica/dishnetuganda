/*
 * 5.18.104's rehearsal: the clock of the processes its live cases run (104-5), moved by a file. Compiled by the rehearsal
 * into its own folder and given to a process and its children with LD_PRELOAD, beside RH104_CLOCK_FILE: the file holds one
 * integer, the seconds added to the wall clock (CLOCK_REALTIME) — read at every call, so that a server started once (the
 * webhook's) follows each move the rehearsal makes; absent, empty or unreadable, nothing is added. time(), gettimeofday()
 * and clock_gettime(CLOCK_REALTIME*) are moved — PHP's time(), date() and DateTime, SQLite's datetime('now') and the
 * shell's date alike; the monotonic clocks and the kernel's file times are not. The clock only ever moves forward, so a
 * stamp written under it is never earlier than one written before it.
 *
 *   gcc -shared -fPIC -O2 -o rh104-clock.so rh104-clock.c -ldl
 */
#define _GNU_SOURCE
#include <dlfcn.h>
#include <fcntl.h>
#include <stdlib.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

static long rh104_offset(void)
{
    const char *f = getenv("RH104_CLOCK_FILE");
    char b[32];
    ssize_t n;
    int fd;
    if (f == NULL || *f == '\0') return 0;
    fd = open(f, O_RDONLY | O_CLOEXEC);
    if (fd < 0) return 0;
    n = read(fd, b, sizeof b - 1);
    close(fd);
    if (n <= 0) return 0;
    b[n] = '\0';
    return strtol(b, NULL, 10);
}

typedef int (*rh104_cg_t)(clockid_t, struct timespec *);
typedef int (*rh104_gt_t)(struct timeval *, void *);

static rh104_cg_t rh104_real_cg(void)
{
    static rh104_cg_t f = NULL;
    if (f == NULL) f = (rh104_cg_t)dlsym(RTLD_NEXT, "clock_gettime");
    return f;
}

static rh104_gt_t rh104_real_gt(void)
{
    static rh104_gt_t f = NULL;
    if (f == NULL) f = (rh104_gt_t)dlsym(RTLD_NEXT, "gettimeofday");
    return f;
}

int clock_gettime(clockid_t c, struct timespec *ts)
{
    int r = rh104_real_cg()(c, ts);
    if (r == 0 && ts != NULL && (c == CLOCK_REALTIME || c == CLOCK_REALTIME_COARSE)) ts->tv_sec += rh104_offset();
    return r;
}

int gettimeofday(struct timeval *restrict tv, void *restrict tz)
{
    int r = rh104_real_gt()(tv, tz);
    if (r == 0 && tv != NULL) tv->tv_sec += rh104_offset();
    return r;
}

time_t time(time_t *t)
{
    struct timespec ts;
    time_t v;
    if (rh104_real_cg()(CLOCK_REALTIME, &ts) != 0) return (time_t)-1;
    v = ts.tv_sec + rh104_offset();
    if (t != NULL) *t = v;
    return v;
}
