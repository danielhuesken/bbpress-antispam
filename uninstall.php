<?PHP
if ( !defined('ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') )
	die();
delete_option('bbpress_antispam_spamcount');
delete_option('bbpress_antispam_spamcart');
delete_option('bbpress_antispam_cfg_schowdashboardchart');
delete_option('bbpress_antispam_cfg_prependspamtitle');
delete_option('bbpress_antispam_cfg_disableloggedinuser');
delete_option('bbpress_antispam_cfg_checkcsshack');
delete_option('bbpress_antispam_cfg_keyhoney');
delete_option('bbpress_antispam_cfg_checkhoney');
delete_option('bbpress_antispam_cfg_checkfakeip');
delete_option('bbpress_antispam_cfg_checkreferer');
delete_option('bbpress_antispam_cfg_checkipspam');
delete_option('bbpress_antispam_cfg_checkcontentspam');
delete_option('bbpress_antispam_cfg_checkauthorspam');
?>
