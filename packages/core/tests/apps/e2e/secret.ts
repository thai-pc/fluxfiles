// Shared secret for the REAL-adapter e2e (React/Vue wrappers driving a live core
// iframe). >= 32 bytes for firebase/php-jwt v7. Used both to write the core
// backend's .env AND to mint matching tokens passed to the wrappers.
export const TEST_SECRET = 'pw-adapter-secret-key-0123456789abcdefgh';

// Ports the harness boots. CORE is the PHP backend the wrappers embed by iframe;
// REACT/VUE are the Vite hosts that render the real @fluxfiles/{react,vue} component.
export const CORE_PORT = 8088;
export const REACT_PORT = 5173;
export const VUE_PORT = 5174;
// Laravel proxy host (php artisan serve). Mints its own token server-side; its
// own .env carries the secret (see laravel-app/.env), so it doesn't use TEST_SECRET.
export const LARAVEL_PORT = 8000;

export const CORE_ENDPOINT = `http://127.0.0.1:${CORE_PORT}`;
