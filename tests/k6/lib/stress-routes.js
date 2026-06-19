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
