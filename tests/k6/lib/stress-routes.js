import { sleep } from 'k6';

// Req 9: 100 VUs for the full run.
export const STRESS_VUS = 100;
export const STRESS_DURATION = '30s';
export const STRESS_BURST_INTERVAL_SEC = 1;

// true  (default) = wall-clock bursts (~100 requests each second tick)
// false           = original free-running (send again as soon as the last request finishes)
// Override at run time: -e STRESS_SYNCHRONIZED_BURST=false
export const STRESS_SYNCHRONIZED_BURST = parseStressBoolean(
    __ENV.STRESS_SYNCHRONIZED_BURST,
    true,
);

// Mixed read routes for req 9 stress (realistic browsing pattern).
export const beforeStressPaths = [
    '/api/before/products?limit=20',
    '/api/before/hot-products?limit=20',
    '/api/before/orders?limit=10',
];

export const afterStressPaths = [
    '/api/after/products?limit=20',
    '/api/after/hot-products?limit=20',
    '/api/after/orders?limit=10',
];

export function pickStressPath(paths) {
    return paths[Math.floor(Math.random() * paths.length)];
}

/** Shared req 9 scenario: 100 VUs for the full duration in both modes. */
export function stressScenarioOptions() {
    if (STRESS_SYNCHRONIZED_BURST) {
        return {
            executor: 'constant-vus',
            vus: STRESS_VUS,
            duration: STRESS_DURATION,
            gracefulStop: '0s',
        };
    }

    return {
        executor: 'ramping-vus',
        startVUs: STRESS_VUS,
        stages: [{ duration: STRESS_DURATION, target: STRESS_VUS }],
        gracefulRampDown: '0s',
    };
}

/** Wait for the next burst tick when synchronized mode is enabled. */
export function maybeWaitForStressBurst() {
    if (STRESS_SYNCHRONIZED_BURST) {
        waitForSynchronizedBurst();
    }
}

/** Align all VUs to the same wall-clock tick before each request. */
export function waitForSynchronizedBurst(intervalSec = STRESS_BURST_INTERVAL_SEC) {
    const nowSec = Date.now() / 1000;
    const nextBurstSec = Math.ceil(nowSec / intervalSec) * intervalSec;
    sleep(Math.max(0, nextBurstSec - nowSec));
}

function parseStressBoolean(raw, defaultValue) {
    if (raw === undefined || raw === '') {
        return defaultValue;
    }

    const normalized = String(raw).trim().toLowerCase();

    if (normalized === '1' || normalized === 'true' || normalized === 'yes') {
        return true;
    }

    if (normalized === '0' || normalized === 'false' || normalized === 'no') {
        return false;
    }

    return defaultValue;
}
