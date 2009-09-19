<?php
/*
Plugin Name: JumpAround Importer
Plugin URI:
Description: Plugin to easy import Flick pictures with a certain tags to wordpress and create a draft
Version: 0.1
Author: Tobias Bielohlawek, Christoph Büttner
Author URI:

Copyright 2009

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if(version_compare(PHP_VERSION, '4.4.0') < 0)
    die(sprintf(__('You are currently running %s and you must have at least PHP 4.4.x in order to use Flickr Manager!', 'flickr-manager'), PHP_VERSION));

if(class_exists('JumpAroundImporter')) return;
require_once(dirname(dirname(__FILE__)) . '/wordpress-flickr-manager/FlickrManager.php');


class JumpAroundImporter extends FlickrManager {

    function JumpAroundImporter() {
        global $wpdb;

        $this->db_table = $wpdb->prefix . "flickr";

        #$this->plugin_directory = 'wordpress-flickr-manager'; #dirname(plugin_basename(__FILE__));
        #$this->plugin_filename = 'wordpress-flickr-manager'; #basename(__FILE__);

        add_action('admin_menu', array(&$this, 'add_menus'));
    }

    function add_menus() {
        add_management_page('JumpAround Importer', 'JP Importer', 10, __FILE__, array(&$this, 'import'));
    }

    function import() {
        if(empty($_REQUEST['import_ids']))  {
            return $this->import_page();
        }
        $this->process($_REQUEST['import_ids']);
    }

    function import_page() {
        global $flickr_settings;
        $token = $flickr_settings->getSetting('token');
        if(empty($token)) {
            echo '<div class="wrap"><h3>' . __('Error: Please authenticate through ', 'flickr-manager') . '<a href="'.get_option('siteurl')."/wp-admin/options-general.php?page=$this->plugin_directory/$this->plugin_filename\">Settings->Flickr</a></h3></div>\n";
            return;
        } else {
            $auth_status = $this->call('flickr.auth.checkToken', array('auth_token' => $token), true);
            if($auth_status['stat'] != 'ok') {
                echo '<div class="wrap"><h3>' . __('Error: Please authenticate through ', 'flickr-manager') . '<a href="'.get_option('siteurl')."/wp-admin/options-general.php?page=$this->plugin_directory/$this->plugin_filename\">Settings->Flickr</a></h3></div>\n";
                return;
            }
        }
        ?>
        <h1>Jump-Around Picture Importer</h1>
        <form method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>" style="width: 650px;">
            <div style="text-align: center;">
                <table style="margin-left: auto; margin-right: auto;" class="widefat">
                    <thead>
                        <tr>
                            <th width="200px"><?php _e('Title', 'flickr-manager'); ?></th>
                            <th><?php _e('Thumbnail', 'flickr-manager'); ?></th>
                        </tr>
                    </thead>

                    <tbody id="the-list">
                        <?php
                        $import_ids = (!empty($_REQUEST['import_ids'])) ? $_REQUEST['import_ids'] : array();
                        foreach($this->all($import_ids) AS $count => $photo) :
                        ?>
                        <tr <?php if($count % 2 > 0) echo "class='alternate'"; ?>>
                            <td align="left"><?php echo $photo['title']; ?><br><?php echo $photo['date']; ?><br><?php echo $photo['tags']; ?></td>
                            <td align="left">
                                <?php foreach( $photo['urls'] AS $url ) {
                                    preg_match('/\/([0-9]+)_/', $url, $matches);
                                    $id = $matches[1];
                                    echo '<img src="'.str_replace('.jpg', '_s.jpg', $url).'" /><input type="checkbox" name="import_ids[]" checked2 value="'.$id.'">';
                                } 
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <input type="submit" name="submit" value="Import selected Pictures" />
            </div>
        </form>
        <?php
    }

    public function process($import_ids) {
        foreach($this->all($import_ids) AS $count => $photo) {
            $post_id = $this->create($photo);
            echo $post_id;
        }
    }

    public function all($import_ids = array()) {
        #return $this->all_mock();
        # get all flickr ids already imported
        $ids = $this->get_all_flickr_ids();

        $params = array('per_page' => 50, 'tags' => 'jump');
        $photos = $this->call_signed('flickr.photos.search', $params);

        $items = array();
        foreach($photos['photos']['photo'] as $photo) {
            if(in_array($photo['id'],$ids)) continue;
            if(count($import_ids) > 0 && !in_array($photo['id'],$import_ids)) continue;
            $geo = $this->getLocation($photo);
            #if(empty($geo)) continue;
            $geo = $geo['latitude'].','.$geo['longitude'];
            if(empty($items[$geo])) $items[$geo] = array('geo' => $geo, 'urls' => array());

            $items[$geo]['title'] .= $photo['title'].' ';
            $items[$geo]['urls'][] = $this->getPhotoUrl($photo, 'medium');

            $info = $this->getInfo($photo);
            $items[$geo]['date'] = $info['dates']['taken'];
            #if(empty($date)) continue;
            $desc = $info['description']['_content'];
            if(stristr($items[$geo]['description'], $desc) === FALSE) $items[$geo]['description'] .= $desc.' ';

            foreach($info['tags']['tag'] AS $raw_tag) {
                $tag = $raw_tag['raw'];
                if($tag != 'jump' && stristr($items[$geo]['tag'], $tag) === FALSE) {
                    $items[$geo]['tags'] .= $tag.",";
                }
            }
        }
        return $items;
    }

    public function all_mock($import_ids = array()) {
        $import_ids[] = array('title' => 'test', 'geo' => '3.3434,45.43434', 'description' => 'Desc', 'tags' => 'a,d,v,g,t', 'date' => '2009-02-02', 'urls' => array('http://farm4.static.flickr.com/3481/3247107635_4e340b929c.jpg','http://farm4.static.flickr.com/3465/3247108339_5d0d1f0929.jpg'));
        return $import_ids;    
    }
    public function getLocation($photo) {
        $params = array('photo_id'=> $photo['id']);
        $res = $this->call_signed('flickr.photos.geo.getLocation', $params );
        return $res['photo']['location'];
    }

    public function getInfo($photo) {
        $params = array('photo_id'=> $photo['id']);
        $res = $this->call_signed('flickr.photos.getInfo', $params );
        return $res['photo'];
    }

    function call_signed( $method, $params) {
        global $flickr_settings;
        $params['user_id'] = $flickr_settings->getSetting('nsid');
        $params['auth_token'] = $flickr_settings->getSetting('token');
        return $this->call($method, $params, true);
    }

    function get_all_flickr_ids() {
        global $wpdb;
        $ids = array();
        $results = $wpdb->get_results( "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key LIKE 'image%'");
        foreach ( $results as $result ) {
            preg_match('/\/([0-9]+)_/', $result->meta_value, $matches);
            $ids[]  = $matches[1];
        }
        return $ids;
    }

    function create($photo) {
        // Create post object
        $my_post = array(
            'post_title'   => $photo['title'],
            'post_content' => $photo['description'],
            'post_date'    => $photo['date'], //The time post was made.
            'tags_input'   => $photo['tags'] //For tags.
        );

        // Insert the post into the database
        $post_id = wp_insert_post( $my_post );

        add_post_meta($post_id, '_geo_location', $photo['geo']);

        foreach( $photo['urls'] AS $index => $url) {
            add_post_meta($post_id, 'image'.($index + 1), $url);
        }
        return $post_id;
    }
    
}

global $jump_around_importer;
$jump_around_importer = new JumpAroundImporter();

?>