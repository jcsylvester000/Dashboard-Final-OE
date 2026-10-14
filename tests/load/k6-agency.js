// E-131: load test for staging (never production). Run with k6 (https://k6.io):
//
//   k6 run -e BASE_URL=https://staging.example.com -e USERS="demo.dev.1@overeasy.local:password,demo.seo.1@overeasy.local:password" \
//          -e WORKSPACE=brewhouse -e TASK_ID=123 tests/load/k6-agency.js
//
// Optional: -e FINANCE="demo.finance.1@overeasy.local:password" -e PERIOD_ID=45 to include building an invoice draft.
// Each virtual user signs in once (sign-in is rate limited per account, so list several accounts),
// then loops: board, task page, comment on the load-test task, timesheet, and (Finance) rebuild a draft.
// Pass: p95 server time < 500 ms and no failed requests at 50 users (thresholds below).
import { check, group, sleep } from 'k6';
import http from 'k6/http';

const BASE = __ENV.BASE_URL || 'http://localhost:8000';
const USERS = (__ENV.USERS || '')
    .split(',')
    .filter(Boolean)
    .map((pair) => pair.split(':'));
const WORKSPACE = __ENV.WORKSPACE || 'brewhouse';
const TASK_ID = __ENV.TASK_ID || '';
const FINANCE = (__ENV.FINANCE || '').split(':');
const PERIOD_ID = __ENV.PERIOD_ID || '';

export const options = {
    scenarios: {
        team: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '2m', target: 50 },
                { duration: '3m', target: 50 },
                { duration: '30s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate==0'],
        http_req_duration: ['p(95)<500'],
    },
};

function xsrf() {
    const jar = http.cookieJar();
    const cookies = jar.cookiesForURL(BASE);
    const token = (cookies['XSRF-TOKEN'] || [])[0] || '';

    return decodeURIComponent(token);
}

function login(email, password) {
    http.get(`${BASE}/login`);
    const res = http.post(
        `${BASE}/login`,
        { email, password },
        {
            headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'text/html' },
            redirects: 0,
            tags: { name: 'login' },
        },
    );
    check(res, { 'signed in': (r) => r.status === 302 || r.status === 303 });
}

let signedIn = false;

export default function () {
    const isFinance =
        FINANCE.length === 2 && PERIOD_ID !== '' && __VU % 10 === 0;
    if (!signedIn) {
        const [email, password] = isFinance
            ? FINANCE
            : USERS[(__VU - 1) % Math.max(1, USERS.length)] || [];
        login(email, password);
        signedIn = true;
    }

    group('board', () => {
        const res = http.get(`${BASE}/w/${WORKSPACE}/board`, {
            tags: { name: 'board' },
        });
        check(res, { 'board 200': (r) => r.status === 200 });
    });

    if (TASK_ID !== '') {
        group('task', () => {
            const res = http.get(`${BASE}/w/${WORKSPACE}/tasks/${TASK_ID}`, {
                tags: { name: 'task page' },
            });
            check(res, { 'task 200': (r) => r.status === 200 });
            const save = http.post(
                `${BASE}/w/${WORKSPACE}/tasks/${TASK_ID}/comments`,
                { body: `Load test ${__VU}-${__ITER}` },
                {
                    headers: { 'X-XSRF-TOKEN': xsrf() },
                    redirects: 0,
                    tags: { name: 'task save (comment)' },
                },
            );
            check(save, {
                'comment saved': (r) => r.status === 302 || r.status === 303,
            });
        });
    }

    group('timesheet', () => {
        const res = http.get(`${BASE}/timesheet`, {
            tags: { name: 'timesheet' },
        });
        check(res, { 'timesheet 200': (r) => r.status === 200 });
    });

    if (isFinance) {
        group('invoice build', () => {
            const res = http.post(
                `${BASE}/billing/periods/${PERIOD_ID}/invoice`,
                {},
                {
                    headers: { 'X-XSRF-TOKEN': xsrf() },
                    redirects: 0,
                    tags: { name: 'invoice build' },
                },
            );
            check(res, {
                'draft built': (r) => r.status === 302 || r.status === 303,
            });
        });
    }

    sleep(1 + Math.random() * 2);
}
