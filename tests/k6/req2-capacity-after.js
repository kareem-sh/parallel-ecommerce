import http from "k6/http";
import { check } from "k6";
import { Rate } from "k6/metrics";
import { baseUrl } from "./lib/common.js";

const capacityRejected = new Rate("capacity_rejected_rate");

export const options = {
    scenarios: {
        guarded_after: {
            executor: "per-vu-iterations",
            vus: 80,
            iterations: 1,
            maxDuration: "30s",
        },
    },
    thresholds: {
        checks: ["rate>0.95"],
        capacity_rejected_rate: ["rate>0"],
    },
};

export default function () {
    const response = http.get(`${baseUrl}/api/after/products?limit=50&simulate_ms=400`, {
        timeout: "15s",
    });

    capacityRejected.add(response.status === 503);

    if (response.status === 503) {
        console.log(`503 received at ${new Date().toISOString()}`);
    }

    check(response, {
        "status is 200 or 503": (r) => r.status === 200 || r.status === 503,
        "503 has correct message": (r) => {
            if (r.status === 503) {
                return (
                    r.json("message") ===
                    "Server capacity is currently full. Please retry shortly."
                );
            }
            return true;
        },
        "200 has backend version": (r) => {
            if (r.status === 200) {
                return r.headers["X-Backend-Version"] === "after";
            }
            return true;
        },
    });
}
