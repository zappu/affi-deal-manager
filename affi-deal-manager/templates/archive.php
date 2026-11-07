<?php
/**
 * Übersicht der Tool-Profile (/tools/ und /rubrik/<slug>/) im Stil der Profile.
 * Kopf- und Fußzeile kommen vom Theme bzw. von Elementor.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
echo ADM_Archive::render(); // phpcs:ignore WordPress.Security.EscapeOutput -- in render() maskiert
get_footer();
