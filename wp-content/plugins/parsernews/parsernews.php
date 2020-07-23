<?php
/* ====================================
* Plugin Name: parsernews
* Description: тестовый плагин для парсера.
* Author: Anatoliy Kerosirov
* Author URI: https://kerosirov.in.ua
* Version: 1.0
* ==================================== */

require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

function get_page_parser( $url )
{
    $curl = curl_init();
    curl_setopt( $curl, CURLOPT_HEADER, 0 );
    curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
    curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, 1 );
    curl_setopt( $curl, CURLOPT_ENCODING, "" );
    curl_setopt( $curl, CURLOPT_TIMEOUT, 60 );
    curl_setopt( $curl, CURLOPT_URL, $url );
    $code = curl_exec( $curl );
    curl_close( $curl );
    return $code;
}

function get_news( $url )
{
    $page = get_page_parser( $url );
    $json_text = find_text_block( $page, '{"bpos":1,"data":{"stream_items":', ',"view":"mega","ccode_fptoday"' );
    $json_news = json_decode( $json_text );
    $news = [];
    if( !is_array( $json_news ) )
        return false;
    foreach( $json_news as $one_news ) {
        $news_data = new stdClass();
        $news_data->id = $one_news->id;
        $news_data->title = $one_news->title;
        $news_data->summary = $one_news->summary;
        $news_data->url = $one_news->url;
        $news_data->img_small = $one_news->images->{'img:220x123'}->url;
        $news_data->img_middle = $one_news->images->{'img:440x246'}->url;
        $news[] = $news_data;
    }
    return $news;
}

function find_text_block( $content = '', $start = '', $finish = '' )
{
    if( $content == '' || $start == '' || $finish == '' )
        return false;
    $p_start = strpos( $content, $start );
    if( $p_start === false )
        return false;
    $len_start = strlen( $start );
    $prom_finish = substr( $content, $p_start + $len_start, strlen( $content ) - 1 );
    $p_finish = strpos( $prom_finish, $finish );
    if( $p_finish === false )
        return false;
    $content = substr( $content, $p_start + $len_start );
    $f_start = strpos( $content, $finish );
    $content_result = substr( $content, 0, $f_start );
    return $content_result;
}

function is_news_id( $id = '' ) {
    if( $id == '' )
        return false;
    global $wpdb;
    $sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE {$wpdb->postmeta}.meta_key='news_id' AND {$wpdb->postmeta}.meta_value='%s'";
    $sql = $wpdb->prepare($sql, $id);
    return $wpdb->query($sql);
}

add_filter( 'cron_schedules', 'parser_add_cron_schedule' );
function parser_add_cron_schedule( $schedules )
{
    $schedules[ 'two_hours' ] = array(
        'interval' => 7200,
        'display' => __( 'Two hours' ),
    );
    return $schedules;
}

if( !wp_next_scheduled( 'parser_add_cron_schedule' ) ) {
    wp_schedule_event( time(), 'two_hours', 'parser_add_cron_schedule' );
}

add_action( 'parser_add_cron_schedule', 'parser_to_run' );
function parser_to_run()
{
    $urls = [
        'news' => 'https://finance.yahoo.com/news/',
        'entertainment' => 'https://www.yahoo.com/entertainment/'
    ];

    foreach( $urls as $category => $url ) {
        $cat_id = get_cat_ID( $category );
        $news = get_news( $url );
        foreach( $news as $one_news ) {
            if( is_news_id( $one_news->id ) )
                continue;
            $post_data = array(
                'post_title' => wp_strip_all_tags( $one_news->title ),
                'post_content' => $one_news->summary,
                'post_status' => 'publish',
                'post_author' => 1,
                'post_category' => array( $cat_id ),
                'meta_input' => [ 'news_id' => $one_news->id ],
            );
            $post_id = wp_insert_post( $post_data );
            $id_img = media_sideload_image( $one_news->img_middle, $post_id, $desc = $one_news->title, $return = 'id' );
            $post_data = array(
                'ID' => $post_id,
                'meta_input' => [ '_thumbnail_id' => $id_img ],
            );
            wp_update_post( $post_data );
        }
    }
}