
// ── FluxFiles live-modules harness (TEST-ONLY) ───────────────────────────────
// /host?u=<user>&o=<json overrides> renders <x-fluxfiles> for that user with
// those token overrides. Taking the policy from the query string is exactly what
// a real app must never do; this app only ever runs inside the test stack.
Route::get('/host', function (\Illuminate\Http\Request $r) {
    $user = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $r->query('u', 'live-user')) ?: 'live-user';
    $overrides = json_decode((string) $r->query('o', '{}'), true);
    auth()->setUser(new \Illuminate\Auth\GenericUser(['id' => $user]));
    return view('host', ['overrides' => is_array($overrides) ? $overrides : []]);
});
