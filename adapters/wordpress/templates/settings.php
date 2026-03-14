<?php defined('ABSPATH') || exit; ?>

<div class="wrap">
    <h1>FluxFiles Settings</h1>

    <form method="post" action="options.php">
        <?php
        settings_fields('fluxfiles');
        do_settings_sections('fluxfiles');
        submit_button();
        ?>
    </form>

    <hr />

    <h2>Quick Embed</h2>
    <p>Use the <code>[fluxfiles]</code> shortcode to embed the file manager in any post or page.</p>
    <table class="form-table">
        <tr>
            <th>Basic</th>
            <td><code>[fluxfiles]</code></td>
        </tr>
        <tr>
            <th>With options</th>
            <td><code>[fluxfiles disk="s3" mode="browser" height="500px"]</code></td>
        </tr>
    </table>

    <h2>PHP Usage</h2>
    <p>Generate a token and embed in your theme template:</p>
    <pre><code>&lt;?php
$token = FluxFilesPlugin::tokenForCurrentUser();
$endpoint = FluxFilesPlugin::apiEndpoint();
?&gt;

&lt;div id="my-file-manager" style="height:600px"&gt;&lt;/div&gt;
&lt;script src="&lt;?= plugins_url('../../fluxfiles.js', FLUXFILES_PLUGIN_FILE) ?&gt;"&gt;&lt;/script&gt;
&lt;script&gt;
FluxFiles.open({
    endpoint: "&lt;?= esc_js($endpoint) ?&gt;",
    token: "&lt;?= esc_js($token) ?&gt;",
    disk: "local",
    mode: "picker",
    container: "#my-file-manager"
});
&lt;/script&gt;</code></pre>
</div>
