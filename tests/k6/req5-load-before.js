import http from "k6/http";
import { check } from "k6";

export const options = {
    scenarios: {
        direct_before: {
            executor: "constant-vus",
            vus: 12,
            duration: "20s",
        },
    },
};

// Use nginx service name (since K6 is on same Docker network)
const baseUrl = __ENV.DIRECT_BASE_URL || "http://nginx:80";

export default function () {
    const response = http.get(`${baseUrl}/api/before/products?limit=20`);

    check(response, {
        "single app instance responded": (r) => r.status === 200,
    });
}
