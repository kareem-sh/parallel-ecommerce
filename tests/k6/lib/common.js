export const baseUrl = __ENV.BASE_URL || "http://nginx:80";

export function reportDate() {
    return __ENV.REPORT_DATE || new Date().toISOString().slice(0, 10);
}
