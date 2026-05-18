import http from "k6/http";
import { check } from "k6";

export const options = {
    scenarios: {
        guarded_after: {
            executor: "constant-vus",
            vus: 100, // 100 concurrent users
            duration: "10s", // Run for 10 seconds
        },
    },
};

const baseUrl = __ENV.BASE_URL || "http://localhost:8000";

export default function () {
    const response = http.get(`${baseUrl}/api/after/products?limit=50`);

    // Track status codes
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
