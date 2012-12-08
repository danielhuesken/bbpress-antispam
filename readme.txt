=== bbPress Antispam ===
Contributors: danielhuesken
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=CS7BVQ6TTCRYU
Tags: bbpress, anti-spam, antispam, spam, forum
Requires at least: 3.2.1
Tested up to: 3.5
Stable tag: 1.0

Antispam for bbPress 2.x

== Description ==

bbPress Antispam for bbPress 2.x is inspired on Antispam Bee[http://wordpress.org/extend/plugins/antispam-bee/] and working similar.
No data is send outside your blog.

Spam detection features
* A CSS hack is made
* DNSBL Servers will checked for known spammers
* Referrer will checked
* The posted content will compared with existing spam

== Installation ==

1. Download Plugin.
2. Decompress and upload the contents of the archive into /wp-content/plugins/.
3. Activate the Plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==
= Where are the settings =
under: Settings > Forms mostly on the bottom

= CSS Hack =
This option filters the most spam.
Renames the name of the textarea for posts and makes an extra hidden with the old name.
Checks than in with is text filled in.

= Nonce Check =
The Nonce check adda a nonce to the post new topic or replay and check it.
That option can only work when in the Template from-reply and from-topic,
the actions for bbp_theme_before_topic_form_content and bbp_theme_before_reply_form_content are set. (Is in default template.)

= DNSBL Check =
The check uses a DNS lookup to opm.tornevall.org and ix.dnsbl.manitu.net (http://www.dnsbl.manitu.net/) to check for known spammers IP's.

= Fake IP Check =
Tries to test that the IP of the poster really exist.

= Referrer Check =
Checks that the sending topic/reply comes for your blog.

= Spam IP Check =
Looks in your comments and form posts, if a post with the poster IP already marked as spam and mark it too.

= Content Spam Check =
Looks in your comments and form posts, if a post with the same content already marked as spam and mark it too.

= Author Spam Check =
Looks in your comments and form posts, if a post with the same author already marked as spam and mark it too.


== Screenshots ==
1. Dashboard
2. Options

== Changelog ==
= 1.0 =
* Fixed: Now works with editor
* Changed: CSS Hack better integration
* Removed: Hony Pot Spam
* Added: two DNBSL for spam detection (without registration)
* Added: more documentation in readme
* Added: Checks that bbPress is loaded to let the Plugin work

= 0.7 =
* Added: help tab
* Fixed: character on sending mail
* Fixed: no CSS spam filter on edit topic/reply
* Changed: Prepending spam type on content not on title

= 0.6 =
* Added: Send mail on new topic/reply
* Changed: Default settings
* Fixed: Grammar

= 0.5 =
* Initial release
