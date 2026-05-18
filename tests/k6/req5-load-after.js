import http from "k6/http";
import { check } from "k6";

export const options = {
    scenarios: {
        balanced_after: {
            executor: "constant-vus",
            vus: 12,
            duration: "20s",
        },
    },
    thresholds: {
        http_req_failed: ["rate<0.05"],
        http_req_duration: ["p(95)<1000"],
    },
};

const baseUrl = __ENV.BASE_URL || "http://nginx:80";

export default function () {
    const response = http.get(`${baseUrl}/api/after/products?limit=20`);

    check(response, {
        "nginx load-balanced response ok": (r) => r.status === 200,
        "after endpoint has correct version": (r) =>
            r.headers["X-Backend-Version"] === "after",
    });
}
