import http from "k6/http";
import { check } from "k6";
import { baseUrl } from "./lib/common.js";

export const options = {
    scenarios: {
        balanced_after: {
            executor: "constant-vus",
            vus: 12,
            duration: "20s",
        },
    },
    thresholds: {
        checks: ["rate>0.95"],
        http_req_duration: ["p(95)<1000"],
    },
};

export default function () {
    const response = http.get(`${baseUrl}/api/after/products?limit=20`);

    check(response, {
        "nginx load-balanced response ok": (r) => r.status === 200,
        "after endpoint has correct version": (r) =>
            r.headers["X-Backend-Version"] === "after",
    });
}
