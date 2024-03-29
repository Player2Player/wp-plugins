<?php

/*
Plugin Name: P2P Custom menu
Plugin URI: https://github.com/Player2Player/wp-custom-menu
Description: Custom menu for getting locations and categories from amelia plugin
Version: 1.0.6
Author: p2p
Author URI: https://player2player.com/
Text Domain: p2p
*/

namespace P2P;

// Const for path root
if (!defined('P2P_PATH')) {
    define('P2P_PATH', __DIR__);
}

class Plugin
{

    /**
     * Track that hooks have been registered w/ WP
     * @var bool
     */
    protected $hasRegistered = false;
    private $locations = array();


    /**
     * Singleton
     *
     * @return Plugin
     */
    public static function init()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self;
        }

        $instance->register();
    }

    private function register()
    {
        if (!is_admin() && !$this->hasRegistered) {
            $this->hasRegistered = true;

            add_filter('wp_nav_menu_objects', array($this, 'addMenuItems'), 10, 2);
        }
    }

    public function addMenuItems($items, $args)
    {
        // only add item to a specific menu
        if ($args->theme_location != 'primary-menu') return $items;

        $items[1]->classes[] = 'menu-item-has-children';
        $items[2]->classes[] = 'menu-item-has-children';
        $searchMenu = (string)$items[1]->ID;
        $bookingMenu = (string)$items[2]->ID;
        $this->getAmeliaLocations();
        $locationsCategories = $this->getAmeliaLocationsCategories();
        $i = 1;
        foreach ($locationsCategories as $menuItem) {
            $top = $this->createMenuItem($menuItem->name,
                $menuItem->landing ? "/{$menuItem->landing}" : "",
                $i++,
                $searchMenu
            );
            $items[] = $top;
            $topBooking = $this->createMenuItem($menuItem->name,
                $menuItem->landing ? "/{$menuItem->landing}" : "",
                $i++,
                $bookingMenu
            );
            $items[] = $topBooking;

            $this->addSubCategoriesMenus($items, $top, $topBooking, $menuItem);
        }

        $user = wp_get_current_user();
        if ($user->exists() && !$user->has_cap('administrator')) {
            $userMenu = $this->createMenuItem("welcome {$user->first_name}", '', $i++);
            $userMenu->classes[] = 'menu-item-has-children';
            $items[] = $userMenu;

            $logoutUrl = wp_logout_url(home_url());
            $bookingUrl = home_url();
            if ($user->has_cap('wpamelia-provider')) {
                $bookingUrl = '/coach-panel';
            } else if ($user->has_cap('wpamelia-customer')) {
                $bookingUrl = '/customer-panel';
            }
            $items[] = $this->createMenuItem("My bookings", $bookingUrl, $i++, $userMenu->ID);
            $items[] = $this->createMenuItem("Logout", $logoutUrl, $i++, $userMenu->ID);
        }
        return $items;
    }

    private function getAmeliaLocationsCategories()
    {
        global $wpdb;
        $locations = $wpdb->get_results($wpdb->prepare(
            "
            SELECT id, name, slug, landing 
            FROM `wp_amelia_locations_categories`
            order by name
            "
        ));
        return $locations;
    }

    /**
     * Simple helper function for make menu item objects
     *
     * @param $title - menu item title
     * @param $url - menu item url
     * @param $order - where the item should appear in the menu
     * @param int $parent - the item's parent item
     * @return WP_Post
     */
    private function createMenuItem($title, $url, $order, $parent = '0')
    {
        $item = new \stdClass();
        $item->ID = 1000000 + $order + $parent;
        $item->db_id = $item->ID;
        $item->title = $title;
        $item->url = $url;
        $item->menu_order = $order;
        $item->menu_item_parent = $parent;
        $item->type = 'custom';
        $item->object = 'custom';
        $item->object_id = (string)$item->ID;
        $item->classes = array();
        $item->target = '';
        $item->attr_title = '';
        $item->description = '';
        $item->xfn = '';
        $item->status = 'publish';
        $item->post_type = 'nav_menu_item';
        return $item;
    }

    public function addSubCategoriesMenus(&$items, $searchMenu, $bookingMenu, $locationItem)
    {
        $locations = $this->getAmeliaLocationsByCategory($locationItem->id);
        $i = 1;
        foreach ($locations as $menuItem) {
            $top = $this->createMenuItem($menuItem->name,
                $menuItem->landing ? "/{$menuItem->landing}" : "/coaches/{$menuItem->slug}",
                $i++,
                $searchMenu->ID
            );
            $items[] = $top;
            $items[] = $this->createMenuItem($menuItem->name,
                $menuItem->landing ? "/{$menuItem->landing}" : "/sports/{$menuItem->slug}",
                $i++,
                $bookingMenu->ID
            );

            $categories = $this->getAmeliaCategories($menuItem->id);
            if (count($categories))
                $items[] = $this->createMenuItem('see all', "/coaches/{$menuItem->slug}", $i++, $top->ID);
            foreach ($categories as $menuCategory) {
                $items[] = $this->createMenuItem($menuCategory->name, "/coaches/{$menuItem->slug}/{$menuCategory->slug}", $i++, $top->ID);
            }
        }
    }

    private function getAmeliaLocationsByCategory($category)
    {
        return array_filter($this->locations, function ($location) use ($category) {
            return $location->locationCategoryId == $category;
        });
    }

    private function getAmeliaLocations()
    {
        global $wpdb;
        $this->locations = $wpdb->get_results($wpdb->prepare(
            "
        SELECT id,name,slug,landing,locationCategoryId FROM `wp_amelia_locations` 
        where status = %s
        order by locationCategoryId, name	
      ",
            'visible'
        ));
    }

    private function getAmeliaCategories($locationId)
    {
        global $wpdb;
        $categories = $wpdb->get_results($wpdb->prepare(
            "select DISTINCT b.name as name, b.slug as slug from
      wp_amelia_providers_to_locations pl 
      inner join wp_amelia_providers_to_services ps on pl.userId = ps.userId
      inner join wp_amelia_services a on a.id = ps.serviceId 
      inner join wp_amelia_categories b on b.id = a.categoryId
      where pl.locationId = %d
      order by b.position     
      ",
            $locationId
        ));
        return $categories;
    }

}

Plugin::init();
