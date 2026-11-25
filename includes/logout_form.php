<?php
/**
 * HumanMatrix - Secure Logout Form
 *
 * Renders a POST-based logout form with CSRF protection.
 */

require_once __DIR__ . "/session.php";

function renderLogoutForm(
    string $action,
    string $class = "",
    string $label = "Logout",
    string $icon = ""
): void {
    // Generate a CSRF token if the current session does not have one.
    if (
        !isset($_SESSION["csrf_token"]) ||
        !is_string($_SESSION["csrf_token"]) ||
        $_SESSION["csrf_token"] === ""
    ) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    $safeAction = htmlspecialchars($action, ENT_QUOTES, "UTF-8");
    $safeClass = htmlspecialchars($class, ENT_QUOTES, "UTF-8");
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, "UTF-8");
    $safeIcon = htmlspecialchars($icon, ENT_QUOTES, "UTF-8");
    $safeToken = htmlspecialchars(
        $_SESSION["csrf_token"],
        ENT_QUOTES,
        "UTF-8"
    );
    ?>

    <form method="POST"
          action="<?= $safeAction ?>"
          class="logout-form">

        <input type="hidden"
               name="csrf_token"
               value="<?= $safeToken ?>">

        <button type="submit" class="<?= $safeClass ?>">
            <?php if ($safeIcon !== ""): ?>
                <span aria-hidden="true"><?= $safeIcon ?></span>
            <?php endif; ?>
            <?= $safeLabel ?>
        </button>
    </form>

    <?php
}
