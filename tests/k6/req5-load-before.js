import http from "k6/http";
import { check } from "k6";
import { baseUrl } from "./lib/common.js";

export const options = {
    scenarios: {
        direct_before: {
            executor: "constant-vus",
            vus: 12,
            duration: "20s",
        },
    },
    thresholds: {
        checks: ["rate>0.95"],
    },
};

export default function () {
    const response = http.get(`${baseUrl}/api/before/products?limit=20`);

    check(response, {
        "single app instance responded": (r) => r.status === 200,
    });
}
