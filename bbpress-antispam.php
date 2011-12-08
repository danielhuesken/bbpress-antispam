<?php
/*
Plugin Name: bbPress Antispam
Plugin URI: http:/danielhuesken.de/portfolio/bbpress-antispam
Description: Antispam for bbPress 2.0
Author: Daniel H&uuml;sken
Version: 0.6
Author URI: http://danielhuesken.de
Text Domain: bbpress-antispam
Domain Path: /lang/
*/

/*
	Copyright (C) 2011  Daniel Hüsken  (email: mail@danielhuesken.de)

	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) 
	exit;

class bbPress_Antispam {
	private $md5_sign='890abcdef';
	private $today='0000-00-00';
	private $plugin_base_name='bbpress-antispam';
	private $spamcount=0;
	private $spamchart=array();
	private $spamtypes=array('csshack','honey','fakeip','referer','ipspam','contentspam','authorspam');

	public function __construct() {
		if ((defined('DOING_AJAX') && DOING_AJAX) or (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) 
			return;
		//set vars corectly
		$this->md5_sign=substr(md5(AUTH_KEY),8,16);
		$this->today=date_i18n('Y-m-d');
		$this->plugin_base_name=untrailingslashit(dirname(plugin_basename(__FILE__)));
		$this->spamcount=get_option('bbpress_antispam_spamcount',$this->spamcount);
		$this->spamchart=get_option('bbpress_antispam_spamcart',$this->spamchart);
		// add var to spam count
		foreach($this->spamtypes as $type) {
			if (!isset($this->spamchart[$this->today][$type]))
				$this->spamchart[$this->today][$type]=0;		
		}
		//remove old days for chart
		if (count($this->spamchart)>30) {
			$mustremoved=count($this->spamchart)-30;
			foreach($this->spamchart as $date => $values) {
				if ($mustremoved<=0)
					break;
				unset($this->spamchart[$date]);
				$mustremoved--;
			}
		}
		//load text domain
		load_plugin_textdomain('bbpress-antispam', false, $this->plugin_base_name.'/lang');
		//add filter and actions
		if (get_option('bbpress_antispam_cfg_checkcsshack','block')!='off')
			add_action('bbp_head',array($this,'ob_start_template_redirect'),100);
		add_filter('bbp_new_topic_pre_content',array($this,'post_pre_content'),1);
		add_filter('bbp_new_reply_pre_content',array($this,'post_pre_content'),1);
		add_filter('bbp_new_topic_pre_insert',array($this,'post_pre_insert'),1);
		add_filter('bbp_new_reply_pre_insert',array($this,'post_pre_insert'),1);
		if (get_option( 'bbpress_antispam_cfg_sendmail', 'off' )!='off') {
			add_action('bbp_new_topic',array($this,'send_mail_topic'),100,1);
			add_action('bbp_new_reply',array($this,'send_mail_reply'),100,1);
		}
		add_action('bbp_dashboard_widget_right_now_content_table_end',array($this,'add_blocked_on_table_end'));
		if (get_option( 'bbpress_antispam_cfg_schowdashboardchart', true ))
			add_action('wp_dashboard_setup',array($this,'init_dashboard_chart'));
		add_action('bbp_register_admin_settings', array($this,'register_admin_settings'));
		add_filter('plugin_row_meta', array($this,'plugin_links'),10,2);
	}
	
	public function ob_start_template_redirect() {
		if (get_option( 'bbpress_antispam_cfg_disableloggedinuser', false ) and is_user_logged_in())
			return;
		?>
		<style type="text/css" media="screen">
		/*<![CDATA[*/
			#bbp_topic_content_css, #bbp_reply_content_css {display:none;width:1px;height:1px;}
		/*]]>*/
		</style>		
		<?PHP
		ob_start(array($this,'ob_callback_template_redirect'));
	}
	
	public function ob_callback_template_redirect($buffer) {
		$buffer=preg_replace("#<textarea(.*?)name=([\"\'])bbp_topic_content([\"\'])(.+?)</textarea>#s", "<textarea id=\"bbp_topic_content_css\" tabindex=\"1112\" name=\"bbp_topic_content\" rows=\"6\" cols=\"2\"></textarea><textarea$1name=$2bbp_topic_content-".$this->md5_sign."$3$4</textarea>", $buffer, 1);
		$buffer=preg_replace("#<textarea(.*?)name=([\"\'])bbp_reply_content([\"\'])(.+?)</textarea>#s", "<textarea id=\"bbp_reply_content_css\" tabindex=\"1112\" name=\"bbp_reply_content\" rows=\"6\"></textarea><textarea$1name=$2bbp_reply_content-".$this->md5_sign."$3$4</textarea>", $buffer, 1);
		return $buffer;
	}

	public function post_pre_content($content) {
		if (get_option( 'bbpress_antispam_cfg_disableloggedinuser', false ) and is_user_logged_in())
			return $content;
		if (get_option('bbpress_antispam_cfg_checkcsshack','block')=='block')
			if ( !empty( $content ) ) {
				bbp_add_error( 'bbp_reply_content', __( '<strong>bbPress ANTISPAM</strong>: CSS Hack!', 'bbpress-antispam' ) );
				$this->count_spam('csshack');		
				return $content;
			}
			//get real content	
			if (current_filter()=='bbp_new_topic_pre_content') {
				$contentname='bbp_topic_content-'.$this->md5_sign;
				if ( !empty( $_POST[$contentname] ) )
					$content = $_POST[$contentname];
			}
			if (current_filter()=='bbp_new_reply_pre_content') {
				$contentname='bbp_reply_content-'.$this->md5_sign;
				if ( !empty( $_POST[$contentname] ) )
					$content = $_POST[$contentname];
			}
		//Filter spam
		if (get_option('bbpress_antispam_cfg_checkhoney','block')=='block' && $this->is_honey_spam() ) {
			bbp_add_error( 'bbp_topic_content', __( '<strong>bbPress ANTISPAM</strong>: Honey Pot!', 'bbpress-antispam' ) );
			$this->count_spam('honey');	
			return $content;
		}
		if (get_option('bbpress_antispam_cfg_checkfakeip','spam')=='block' && $this->is_fake_ip() ) {
			bbp_add_error( 'bbp_topic_content', __( '<strong>bbPress ANTISPAM</strong>: Fake IP!', 'bbpress-antispam' ) );
			$this->count_spam('fakeip');	
			return $content;
		}
		if (get_option('bbpress_antispam_cfg_checkreferer','spam')=='block' && $this->is_flase_referer() ) {
			bbp_add_error( 'bbp_reply_content', __( '<strong>bbPress ANTISPAM</strong>: Referer!', 'bbpress-antispam' ) );
			$this->count_spam('referer');			
			return $content;
		}
		if (get_option('bbpress_antispam_cfg_checkipspam','block')=='block' && $this->is_ip_spam() ) {
			bbp_add_error( 'bbp_reply_content', __( '<strong>bbPress ANTISPAM</strong>: Spam IP!', 'bbpress-antispam' ) );
			$this->count_spam('ipspam');			
			return $content;
		}
		if (get_option('bbpress_antispam_cfg_checkcontentspam','block')=='block' && $this->is_content_spam($content) ) {
			bbp_add_error( 'bbp_reply_content', __( '<strong>bbPress ANTISPAM</strong>: Spam content!', 'bbpress-antispam' ) );
			$this->count_spam('contentspam');			
			return $content;
		}
		return $content;
	}

	public function post_pre_insert($post_data) {
		if (get_option( 'bbpress_antispam_cfg_disableloggedinuser', false ) and is_user_logged_in())
			return $post_data;
		if (get_option('bbpress_antispam_cfg_checkhoney','block')=='spam' && $this->is_honey_spam()) {
			if (get_option( 'bbpress_antispam_cfg_prependspamtitle', true ))
				$post_data['post_title'] = __( 'bbPress ANTISPAM: Honey Pot:', 'bbpress-antispam' )." ".$post_data['post_title'];
			$post_data['post_status'] = bbp_get_spam_status_id();
			$this->count_spam('honey');
			return $post_data;
		}
		if (get_option('bbpress_antispam_cfg_checkfakeip','spam')=='spam' && $this->is_fake_ip()) {
			if (get_option( 'bbpress_antispam_cfg_prependspamtitle', true ))
				$post_data['post_title'] = __( 'bbPress ANTISPAM: Fake IP:', 'bbpress-antispam' )." ".$post_data['post_title'];
			$post_data['post_status'] = bbp_get_spam_status_id();
			$this->count_spam('fakeip');
			return $post_data;
		}
		if (get_option('bbpress_antispam_cfg_checkreferer','spam')=='spam' && $this->is_flase_referer()) {
			if (get_option( 'bbpress_antispam_cfg_prependspamtitle', true ))
				$post_data['post_title'] = __( 'bbPress ANTISPAM: Referer:', 'bbpress-antispam' )." ".$post_data['post_title'];
			$post_data['post_status'] = bbp_get_spam_status_id();
			$this->count_spam('referer');
			return $post_data;
		}
		if (get_option('bbpress_antispam_cfg_checkipspam','block')=='spam' && $this->is_ip_spam()) {
			if (get_option( 'bbpress_antispam_cfg_prependspamtitle', true ))
				$post_data['post_title'] = __( 'bbPress ANTISPAM: Spam IP:', 'bbpress-antispam' )." ".$post_data['post_title'];
			$post_data['post_status'] = bbp_get_spam_status_id();
			$this->count_spam('ipspam');
			return $post_data;
		}
		if (get_option('bbpress_antispam_cfg_checkcontentspam','block')=='spam' && $this->is_content_spam($post_data['post_content'])) {
			if (get_option( 'bbpress_antispam_cfg_prependspamtitle', true ))
				$post_data['post_title'] = __( 'bbPress ANTISPAM: Spam content:', 'bbpress-antispam' )." ".$post_data['post_title'];
			$post_data['post_status'] = bbp_get_spam_status_id();
			$this->count_spam('contentspam');
			return $post_data;
		}
		if (get_option('bbpress_antispam_cfg_checkauthorspam','spam')=='spam' && $this->is_author_spam($post_data['post_author'])) {
			if (get_option( 'bbpress_antispam_cfg_prependspamtitle', true ))
				$post_data['post_title'] = __( 'bbPress ANTISPAM: Spam author:', 'bbpress-antispam' )." ".$post_data['post_title'];
			$post_data['post_status'] = bbp_get_spam_status_id();
			$this->count_spam('authorspam');
			return $post_data;
		}
		return $post_data;
	}

	private function count_spam($type) {
		$type=strtolower($type);
		if (in_array($type,$this->spamtypes))
			$this->spamchart[$this->today][$type]++;
		$this->spamcount++;
		update_option('bbpress_antispam_spamcart',$this->spamchart);
		update_option('bbpress_antispam_spamcount',$this->spamcount);
	}

	public function send_mail_reply($reply_id) {
		if ((get_option('bbpress_antispam_cfg_sendmail', 'off')=='spam' and bbp_get_reply_status($reply_id) != bbp_get_spam_status_id()))
			return;
		$author_mail=bbp_get_reply_author_email($reply_id);
		$author_name=bbp_get_reply_author_display_name($reply_id);
		if (bbp_get_reply_status($reply_id) == bbp_get_spam_status_id()) {
			$subject = sprintf( __('[%1$s] SPAM reply on: "%2$s"','bbpress-antispam'), wp_specialchars_decode(get_option('blogname'), ENT_QUOTES), bbp_get_topic_title($reply_id) );
			$notify_message  = sprintf( __( 'New "marked as SPAM" reply on the topic "%s"','bbpress-antispam' ), bbp_get_reply_title($reply_id) ) . "\r\n";
		} else {
			$subject = sprintf( __('[%1$s] Reply on: "%2$s"','bbpress-antispam'), wp_specialchars_decode(get_option('blogname'), ENT_QUOTES), bbp_get_topic_title($reply_id) );
			$notify_message  = sprintf( __( 'New reply on the topic "%s"','bbpress-antispam' ),  bbp_get_topic_title($reply_id) ) . "\r\n";
		}
		$notify_message .= sprintf( __('Author : %1$s (IP: %2$s , %3$s)','bbpress-antispam'), $author_name, $_SERVER['REMOTE_ADDR'], @gethostbyaddr($_SERVER['REMOTE_ADDR']) ) . "\r\n";
		$notify_message .= sprintf( __('E-mail : %s','bbpress-antispam'), $author_mail ) . "\r\n";
		$notify_message .= sprintf( __('URL    : %s','bbpress-antispam'), bbp_reply_author_url($reply_id) ) . "\r\n";
		$notify_message .= sprintf( __('Whois  : http://whois.arin.net/rest/ip/%s','bbpress-antispam'), $_SERVER['REMOTE_ADDR'] ) . "\r\n";
		$notify_message .= __('Reply: ','bbpress-antispam') . "\r\n" . strip_tags(bbp_get_reply_content($reply_id)) . "\r\n\r\n";
		$notify_message .= sprintf( __('Permalink: %s','bbpress-antispam'), bbp_get_reply_url($reply_id))  . "\r\n";
		$wp_email = 'bbPress@' . preg_replace('#^www\.#', '', strtolower($_SERVER['SERVER_NAME']));
		if ( '' == $author_name ) {
			$from = "From: \"".wp_specialchars_decode(get_option('blogname'), ENT_QUOTES)."\" <$wp_email>";
			if ( '' != $author_mail )
				$reply_to = "Reply-To: $author_mail";
		} else {
			$from = "From: \"$author_name\" <$wp_email>";
			if ( '' != $author_mail )
				$reply_to = "Reply-To: $author_mail";
		}
		$message_headers = "$from\n". "Content-Type: text/plain; charset=\"" . get_option('blog_charset') . "\"\n";
		if ( isset($reply_to) )
			$message_headers .= $reply_to . "\n";
		@wp_mail( get_bloginfo( 'admin_email' ), $subject, $notify_message, $message_headers );
	}

	public function send_mail_topic($topic_id) {
		if ((get_option('bbpress_antispam_cfg_sendmail', 'off')=='spam' and bbp_get_topic_status($topic_id) != bbp_get_spam_status_id()))
			return;
		$author_mail=bbp_get_topic_author_email($topic_id);
		$author_name=bbp_get_topic_author_display_name($topic_id);
		if (bbp_get_reply_status($topic_id) == bbp_get_spam_status_id()) {
			$subject = sprintf( __('[%1$s] SPAM topic: "%2$s"','bbpress-antispam'), wp_specialchars_decode(get_option('blogname'), ENT_QUOTES), bbp_get_topic_title($topic_id) );
			$notify_message  = sprintf( __( 'New "marked as SPAM" reply on the topic "%s"','bbpress-antispam' ), bbp_get_topic_title($topic_id) ) . "\r\n";
		} else {
			$subject = sprintf( __('[%1$s] Topic: "%2$s"','bbpress-antispam'), wp_specialchars_decode(get_option('blogname'), ENT_QUOTES), bbp_get_topic_title($topic_id) );
			$notify_message  = sprintf( __( 'New reply on the topic "%s"','bbpress-antispam' ),  bbp_get_topic_title($topic_id) ) . "\r\n";
		}
		$notify_message .= sprintf( __('Author : %1$s (IP: %2$s , %3$s)','bbpress-antispam'), $author_name, $_SERVER['REMOTE_ADDR'], @gethostbyaddr($_SERVER['REMOTE_ADDR']) ) . "\r\n";
		$notify_message .= sprintf( __('E-mail : %s','bbpress-antispam'), $author_mail ) . "\r\n";
		$notify_message .= sprintf( __('URL    : %s','bbpress-antispam'), bbp_topic_author_url($topic_id) ) . "\r\n";
		$notify_message .= sprintf( __('Whois  : http://whois.arin.net/rest/ip/%s','bbpress-antispam'), $_SERVER['REMOTE_ADDR'] ) . "\r\n";
		$notify_message .= __('Reply: ','bbpress-antispam') . "\r\n" . strip_tags(bbp_get_topic_content($topic_id)) . "\r\n\r\n";
		$notify_message .= sprintf( __('Permalink: %s','bbpress-antispam'), bbp_get_topic_permalink($topic_id))  . "\r\n";
		$wp_email = 'bbPress@' . preg_replace('#^www\.#', '', strtolower($_SERVER['SERVER_NAME']));
		if ( '' == $author_name ) {
			$from = "From: \"".wp_specialchars_decode(get_option('blogname'), ENT_QUOTES)."\" <$wp_email>";
			if ( '' != $author_mail )
				$reply_to = "Reply-To: $author_mail";
		} else {
			$from = "From: \"$author_name\" <$wp_email>";
			if ( '' != $author_mail )
				$reply_to = "Reply-To: $author_mail";
		}
		$message_headers = "$from\n". "Content-Type: text/plain; charset=\"" . get_option('blog_charset') . "\"\n";
		if ( isset($reply_to) )
			$message_headers .= $reply_to . "\n";
		@wp_mail( get_bloginfo( 'admin_email' ), $subject, $notify_message, $message_headers );
	}

	private function is_honey_spam() {
		$key=get_option('bbpress_antispam_cfg_keyhoney');
		if (!empty($key))
			return false;
		$ip=$_SERVER['REMOTE_ADDR'];
		if ( empty($ip) )
			return true;
		$host = sprintf('%s.%s.dnsbl.httpbl.org',$key,implode('.',array_reverse(explode('.',$ip))));
		$bits = explode('.',gethostbyname($host));
		return ( $bits[0] == 127 && $bits[3] & 4 );
	}
	
	private function is_fake_ip() {
		$ip=$_SERVER['REMOTE_ADDR'];
		if ( empty($ip) )
			return true;
		$checkedip=gethostbyname(gethostbyaddr($ip));
		if ($ip==$checkedip or $checkedip=='127.0.0.1' or $ip=='127.0.0.1')
			return false;
		else
			return true;
	}

	private function is_flase_referer() {
		$referer=strtolower($_SERVER['HTTP_REFERER']);
		if ( empty($referer) )
			return true;
		if (strpos($referer,strtolower(get_bloginfo('url'))) == 0)
			return false;
		else
			return true;
	}
	
	private function is_ip_spam() {
		global $wpdb;
		$ip=$_SERVER['REMOTE_ADDR'];
		if ( empty($ip) )
			return true;
		$found = $wpdb->get_var($wpdb->prepare("SELECT `comment_ID` FROM `$wpdb->comments` WHERE `comment_approved` = 'spam' AND `comment_author_IP` = %s LIMIT 1",(string)$ip));
		if ( $found )
			return true;
		$found = $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM $wpdb->posts p LEFT JOIN $wpdb->postmeta m ON p.ID = m.post_id WHERE p.post_status = 'spam' AND (p.post_type ='reply' OR p.post_type ='topic') AND m.meta_key = '_bbp_author_ip' AND m.meta_value = %s LIMIT 1",(string)$ip));
		if ( $found )
			return true;
		return false;
	}
	
	private function is_content_spam($content) {
		global $wpdb;
		$found = $wpdb->get_var($wpdb->prepare("SELECT `ID` FROM `$wpdb->posts` WHERE `post_status` = 'spam' AND (`post_type` ='reply' OR `post_type` ='topic') AND `post_content` = %s LIMIT 1",$content));
		if ( $found )
			return true;
		$found = $wpdb->get_var($wpdb->prepare("SELECT `comment_ID` FROM `$wpdb->comments` WHERE `comment_approved` = 'spam' AND `comment_content` = %s LIMIT 1",$content));
		if ( $found )
			return true;
		return false;
	}
	
	private function is_author_spam($author) {
		global $wpdb;
		$userdata = get_userdata( $author );
		$anonymous_data = bbp_filter_anonymous_post_data();
		if ( !empty( $anonymous_data ) ) {
			$user_data['name']    = $anonymous_data['bbp_anonymous_name'];
		} elseif ( !empty( $userdata ) ) {
			$user_data['name']    = $userdata->display_name;
		} else {
			return true;
		}
		$found = $wpdb->get_var($wpdb->prepare("SELECT `comment_ID` FROM `$wpdb->comments` WHERE `comment_approved` = 'spam' AND `comment_author` = %s LIMIT 1",$user_data['name']));
		if ( $found )
			return true;
		$found = $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM $wpdb->posts p LEFT JOIN $wpdb->postmeta m ON p.ID = m.post_id WHERE p.post_status = 'spam' AND (p.post_type ='reply' OR p.post_type ='topic') AND m.meta_key = '_bbp_anonymous_name' AND m.meta_value = %s LIMIT 1",$user_data['name']));
		if ( $found )
			return true;
		return false;
	}
	
	public function add_blocked_on_table_end() {
		echo sprintf('<tr><td class="b b-spam" style="font-size:18px">%s</td><td class="last t b-spam">%s</td></tr>',esc_html($this->spamcount),esc_html__('Blocked', 'bbpress-antispam'));
	}
	

	public function init_dashboard_chart() {
		if ( !current_user_can('administrator') )
			return false;
		wp_add_dashboard_widget('bbpress_antispam','bbPress Antispam',array($this,'dashboard_show_spam_chart'));
		add_action('admin_head-index.php',array($this,'add_dashboard_head'));
	}
	
	public function add_dashboard_head() { 
		?>
		<style type="text/css" media="screen">
		/*<![CDATA[*/
			#bbpress_antispam_chart{height:200px;}
		/*]]>*/
		</style>
		<script type="text/javascript" src="https://www.google.com/jsapi"></script>
		<script type="text/javascript"> 
			google.load("visualization", "1", {packages:["corechart"]});
			google.setOnLoadCallback(drawChart);
			function drawChart() {
				var data = new google.visualization.DataTable();
				data.addColumn('string', '<?PHP _e('Date','bbpress-antispam'); ?>');
				data.addColumn('number', '<?PHP _e('Summary','bbpress-antispam'); ?>');
				data.addColumn('number', '<?PHP _e('CSS Hack','bbpress-antispam'); ?>');
				data.addColumn('number', '<?PHP _e('Honey Pot','bbpress-antispam'); ?>');
				data.addColumn('number', '<?PHP _e('Fake IP','bbpress-antispam'); ?>');
				data.addColumn('number', '<?PHP _e('Referer','bbpress-antispam'); ?>');
				data.addColumn('number', '<?PHP _e('Spam IP','bbpress-antispam'); ?>');
				data.addColumn('number', '<?PHP _e('Spam content','bbpress-antispam'); ?>');
				data.addColumn('number', '<?PHP _e('Spam author','bbpress-antispam'); ?>');
				data.addRows([<?php
				$data='';
				foreach($this->spamchart as $day => $dayvalue) {
					$count=0;
					if (!is_array($dayvalue)) 
						continue;
					foreach($dayvalue as $key => $value)
						$count=$count+$value;
					$data.= "['".$day."',".$count;
					foreach($this->spamtypes as $type) {
						$data.= ",".$dayvalue[$type];
					}
					$data.= "],";
				}
				echo substr($data,0,-1);
				?>]);
				var chart = new google.visualization.AreaChart(document.getElementById('bbpress_antispam_chart'));
				chart.draw(data, {width: parseInt(jQuery("#bbpress_antispam_chart").parent().width(),10), height: 200,
					legend: 'none', pointSize: 4, lineWidth: 2, gridlineColor: '#ececec',
					colors:['black','red','blue','green','yellow','Brown','Aquamarine','DarkViolet'], chartArea:{width: '100%',height: '100%'},
					backgroundColor: 'transparent', vAxis: {baselineColor: 'transparent', textPosition: 'in'}});
			}
		</script>
		<?php
	}

	public function dashboard_show_spam_chart() {
		echo '<div id="bbpress_antispam_chart"></div>';
	}
	
	public function plugin_links($links, $file) {
		if (!current_user_can('install_plugins'))
			return $links;
		if ($file == $this->plugin_base_name.'/bbpress-antispam.php') {
			$links[] = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=DYTLEJTRVDWAU" target="_blank">' . __('Donate','backwpup') . '</a>';
		}
		return $links;
	}
	
	public function register_admin_settings() {
	
		add_settings_section('bbpress_antispam',__( 'Antispam','bbpress-antispam'), array($this,'callback_main_section'),'bbpress');

		add_settings_field('bbpress_antispam_cfg_generl',__( 'Generel Settings', 'bbpress-antispam'), array($this,'option_callback_generel'),'bbpress','bbpress_antispam');
	 	register_setting('bbpress','bbpress_antispam_cfg_schowdashboardchart','intval');
		register_setting('bbpress','bbpress_antispam_cfg_prependspamtitle','intval');
		register_setting('bbpress','bbpress_antispam_cfg_disableloggedinuser','intval');
		register_setting('bbpress','bbpress_antispam_cfg_sendmail','strval');
		
		add_settings_field('bbpress_antispam_cfg_checkcsshack',__( 'Use and check with CSS Hack', 'bbpress-antispam'), array($this,'option_callback_checkcsshack'),'bbpress','bbpress_antispam');
	 	register_setting('bbpress','bbpress_antispam_cfg_checkcsshack','strval');
		
		add_settings_field('bbpress_antispam_cfg_checkhoney',__( 'Use Hony Pot Project', 'bbpress-antispam'), array($this,'option_callback_checkhoney'),'bbpress','bbpress_antispam');
	 	register_setting('bbpress','bbpress_antispam_cfg_checkhoney','strval');
		register_setting('bbpress','bbpress_antispam_cfg_keyhoney','strval');
		
		add_settings_field('bbpress_antispam_cfg_checkfakeip',__( 'Check for fake IP address', 'bbpress-antispam'), array($this,'option_callback_checkfakeip'),'bbpress','bbpress_antispam');
	 	register_setting('bbpress','bbpress_antispam_cfg_checkfakeip','strval');
		
		add_settings_field('bbpress_antispam_cfg_checkreferer',__( 'Check Referer', 'bbpress-antispam'), array($this,'option_callback_checkreferer'),'bbpress','bbpress_antispam');
	 	register_setting('bbpress','bbpress_antispam_cfg_checkreferer','strval');
		
		add_settings_field('bbpress_antispam_cfg_checkipspam',__( 'Is IP already marked as Spam IP', 'bbpress-antispam'), array($this,'option_callback_checkipspam'),'bbpress','bbpress_antispam');
	 	register_setting('bbpress','bbpress_antispam_cfg_checkipspam','strval');
		
		add_settings_field('bbpress_antispam_cfg_checkcontentspam',__( 'Is content already marked as Spam', 'bbpress-antispam'), array($this,'option_callback_checkcontentspam'),'bbpress','bbpress_antispam');
	 	register_setting('bbpress','bbpress_antispam_cfg_checkcontentspam','strval');
		
		add_settings_field('bbpress_antispam_cfg_checkauthorspam',__( 'Is Author already marked as Spam Author', 'bbpress-antispam'), array($this,'option_callback_checkauthorspam'),'bbpress','bbpress_antispam');
	 	register_setting('bbpress','bbpress_antispam_cfg_checkauthorspam','strval');

	}
	
	public function callback_main_section() {
		?>
		<p id="bbpress-antispam"><?php _e( 'Antispam Configuration', 'bbpress-antispam' ); ?></p>
		<?php
	}
	
	public function option_callback_generel() {
		?>
		<input id="bbpress_antispam_cfg_generl_schowdashboardchart" name="bbpress_antispam_cfg_schowdashboardchart" type="checkbox" value="1" <?php checked( get_option( 'bbpress_antispam_cfg_schowdashboardchart', true ) ); ?> />
		<label for="bbpress_antispam_cfg_generl_schowdashboardchart"><?php _e( 'Show chart in Dashboard', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_generl_prependspamtitle" name="bbpress_antispam_cfg_prependspamtitle" type="checkbox" value="1" <?php checked( get_option( 'bbpress_antispam_cfg_prependspamtitle', true ) ); ?> />
		<label for="bbpress_antispam_cfg_generl_prependspamtitle"><?php _e( 'Prepend title with SPAM message on move to Spam', 'bbpress-antispam' ); ?></label>
		<br />		
		<input id="bbpress_antispam_cfg_generl_disableloggedinuser" name="bbpress_antispam_cfg_disableloggedinuser" type="checkbox" value="1" <?php checked( get_option( 'bbpress_antispam_cfg_disableloggedinuser', false ) ); ?> />
		<label for="bbpress_antispam_cfg_generl_disableloggedinuser"><?php _e( 'Do not scan logged in users', 'bbpress-antispam' ); ?></label>
		<br />
		<label for="bbpress_antispam_cfg_sendmail"><?php _e( 'Send mail if new reply/topic ?', 'bbpress-antispam' ); ?></label>
		<select id="bbpress_antispam_cfg_sendmail" name="bbpress_antispam_cfg_sendmail">
			<option <?php selected( get_option('bbpress_antispam_cfg_sendmail')=='ever' ); ?>><?php _e( 'Ever', 'bbpress-antispam' ); ?></option>
			<option <?php selected( get_option('bbpress_antispam_cfg_sendmail')=='spam' ); ?>><?php _e( 'Spam only', 'bbpress-antispam' ); ?></option>
			<option <?php selected( get_option('bbpress_antispam_cfg_sendmail','off')=='off' ); ?>><?php _e( 'Disable', 'bbpress-antispam' ); ?></option>
		</select>

		<br />
		<?php
	}

	public function option_callback_checkcsshack() {
		?>
		<input id="bbpress_antispam_cfg_checkcsshack_block" name="bbpress_antispam_cfg_checkcsshack" type="radio" value="block" <?php checked( get_option('bbpress_antispam_cfg_checkcsshack','block')=='block' ); ?> />
		<label for="bbpress_antispam_cfg_checkcsshack_block"><?php _e( 'Block', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkcsshack_off" name="bbpress_antispam_cfg_checkcsshack" type="radio" value="off" <?php checked( get_option('bbpress_antispam_cfg_checkcsshack')=='off' ); ?> />
		<label for="bbpress_antispam_cfg_checkcsshack_off"><?php _e( 'Disable', 'bbpress-antispam' ); ?></label>
		<?php
	}
	
	public function option_callback_checkhoney() {
		?>
		<input id="bbpress_antispam_cfg_keyhoney" name="bbpress_antispam_cfg_keyhoney" type="text" class="text code" value="<?php echo get_option('bbpress_antispam_cfg_keyhoney'); ?>" />
		<label for="bbpress_antispam_cfg_keyhoney"><a href="http://www.projecthoneypot.org/index.php"><?php _e( 'Hony Pot Key', 'bbpress-antispam' ); ?></a></label>
		<br />
		<input id="bbpress_antispam_cfg_checkhoney_block" name="bbpress_antispam_cfg_checkhoney" type="radio" value="block" <?php checked( get_option('bbpress_antispam_cfg_checkhoney','block')=='block' ); ?> />
		<label for="bbpress_antispam_cfg_checkhoney_block"><?php _e( 'Block', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkhoney_spam" name="bbpress_antispam_cfg_checkhoney" type="radio" value="spam" <?php checked( get_option('bbpress_antispam_cfg_checkhoney')=='spam' ); ?> />
		<label for="bbpress_antispam_cfg_checkhoney_spam"><?php _e( 'Move to Spam', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkhoney_off" name="bbpress_antispam_cfg_checkhoney" type="radio" value="off" <?php checked( get_option('bbpress_antispam_cfg_checkhoney')=='off' ); ?> />
		<label for="bbpress_antispam_cfg_checkhoney_off"><?php _e( 'Disable', 'bbpress-antispam' ); ?></label>
		<?php
	}	

	public function option_callback_checkfakeip() {
		?>
		<input id="bbpress_antispam_cfg_checkfakeip_block" name="bbpress_antispam_cfg_checkfakeip" type="radio" value="block" <?php checked( get_option('bbpress_antispam_cfg_checkfakeip')=='block' ); ?> />
		<label for="bbpress_antispam_cfg_checkfakeip_block"><?php _e( 'Block', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkfakeip_spam" name="bbpress_antispam_cfg_checkfakeip" type="radio" value="spam" <?php checked( get_option('bbpress_antispam_cfg_checkfakeip','spam')=='spam' ); ?> />
		<label for="bbpress_antispam_cfg_checkfakeip_spam"><?php _e( 'Move to Spam', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkfakeip_off" name="bbpress_antispam_cfg_checkfakeip" type="radio" value="off" <?php checked( get_option('bbpress_antispam_cfg_checkfakeip')=='off' ); ?> />
		<label for="bbpress_antispam_cfg_checkfakeip_off"><?php _e( 'Disable', 'bbpress-antispam' ); ?></label>
		<?php
	}	

	public function option_callback_checkreferer() {
		?>
		<input id="bbpress_antispam_cfg_checkreferer_block" name="bbpress_antispam_cfg_checkreferer" type="radio" value="block" <?php checked( get_option('bbpress_antispam_cfg_checkreferer')=='block' ); ?> />
		<label for="bbpress_antispam_cfg_checkreferer_block"><?php _e( 'Block', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkreferer_spam" name="bbpress_antispam_cfg_checkreferer" type="radio" value="spam" <?php checked( get_option('bbpress_antispam_cfg_checkreferer','spam')=='spam' ); ?> />
		<label for="bbpress_antispam_cfg_checkreferer_spam"><?php _e( 'Move to Spam', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkreferer_off" name="bbpress_antispam_cfg_checkreferer" type="radio" value="off" <?php checked( get_option('bbpress_antispam_cfg_checkreferer')=='off' ); ?> />
		<label for="bbpress_antispam_cfg_checkreferer_off"><?php _e( 'Disable', 'bbpress-antispam' ); ?></label>
		<?php
	}
	
	public function option_callback_checkipspam() {
		?>
		<input id="bbpress_antispam_cfg_checkipspam_block" name="bbpress_antispam_cfg_checkipspam" type="radio" value="block" <?php checked( get_option('bbpress_antispam_cfg_checkipspam','block')=='block' ); ?> />
		<label for="bbpress_antispam_cfg_checkipspam_block"><?php _e( 'Block', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkipspam_spam" name="bbpress_antispam_cfg_checkipspam" type="radio" value="spam" <?php checked( get_option('bbpress_antispam_cfg_checkipspam')=='spam' ); ?> />
		<label for="bbpress_antispam_cfg_checkipspam_spam"><?php _e( 'Move to Spam', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkipspam_off" name="bbpress_antispam_cfg_checkipspam" type="radio" value="off" <?php checked( get_option('bbpress_antispam_cfg_checkipspam')=='off' ); ?> />
		<label for="bbpress_antispam_cfg_checkipspam_off"><?php _e( 'Disable', 'bbpress-antispam' ); ?></label>
		<?php
	}
	
	public function option_callback_checkcontentspam() {
		?>
		<input id="bbpress_antispam_cfg_checkcontentspam_block" name="bbpress_antispam_cfg_checkcontentspam" type="radio" value="block" <?php checked( get_option('bbpress_antispam_cfg_checkcontentspam','block')=='block' ); ?> />
		<label for="bbpress_antispam_cfg_checkcontentspam_block"><?php _e( 'Block', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkcontentspam_spam" name="bbpress_antispam_cfg_checkcontentspam" type="radio" value="spam" <?php checked( get_option('bbpress_antispam_cfg_checkcontentspam')=='spam' ); ?> />
		<label for="bbpress_antispam_cfg_checkcontentspam_spam"><?php _e( 'Move to Spam', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkcontentspam_off" name="bbpress_antispam_cfg_checkcontentspam" type="radio" value="off" <?php checked( get_option('bbpress_antispam_cfg_checkcontentspam')=='off' ); ?> />
		<label for="bbpress_antispam_cfg_checkcontentspam_off"><?php _e( 'Disable', 'bbpress-antispam' ); ?></label>
		<?php
	}
	
	public function option_callback_checkauthorspam() {
		?>
		<input id="bbpress_antispam_cfg_checkauthorspam_spam" name="bbpress_antispam_cfg_checkauthorspam" type="radio" value="spam" <?php checked( get_option('bbpress_antispam_cfg_checkauthorspam','spam')=='spam' ); ?> />
		<label for="bbpress_antispam_cfg_checkauthorspam_spam"><?php _e( 'Move to Spam', 'bbpress-antispam' ); ?></label>
		<br />
		<input id="bbpress_antispam_cfg_checkauthorspam_off" name="bbpress_antispam_cfg_checkauthorspam" type="radio" value="off" <?php checked( get_option('bbpress_antispam_cfg_checkauthorspam')=='off' ); ?> />
		<label for="bbpress_antispam_cfg_checkauthorspam_off"><?php _e( 'Disable', 'bbpress-antispam' ); ?></label>
		<?php
	}

}
//Start
new bbPress_Antispam();
?>