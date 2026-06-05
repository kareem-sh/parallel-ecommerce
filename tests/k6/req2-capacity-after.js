import http from "k6/http";
import { check, sleep } from "k6";
import { Rate } from "k6/metrics";
import { baseUrl } from "./lib/common.js";

const capacityRejected = new Rate("capacity_rejected_rate");

const CAPACITY_MESSAGE =
    "Server capacity is currently full. Please retry shortly.";

export const options = {
    scenarios: {
        guarded_after: {
            executor: "per-vu-iterations",
            vus: 100,
            iterations: 1,
            maxDuration: "45s",
            gracefulStop: "0s",
        },
    },
    thresholds: {
        checks: ["rate>0.95"],
        capacity_rejected_rate: ["rate>0.10"],
    },
};

export function setup() {
    http.get(`${baseUrl}/api/health`);
    sleep(0.5);
}

export default function () {
    const response = http.get(
        `${baseUrl}/api/after/products?limit=50&simulate_ms=2000`,
        { timeout: "30s" },
    );

    const is503 = response.status === 503;
    capacityRejected.add(is503);

    check(response, {
        "status is 200 or 503": (r) => r.status === 200 || r.status === 503,
        "503 has correct body": (r) =>
            r.status !== 503 ||
            (r.json("message") === CAPACITY_MESSAGE &&
                r.json("max_capacity") === 25),
        "200 has backend version": (r) =>
            r.status !== 200 ||
            r.headers["X-Backend-Version"] === "after",
    });
}
