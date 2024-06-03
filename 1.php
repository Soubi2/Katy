<?php
/**
 * @package VidSrc PsyPlay
 * @version 1.0
 */
/*
Plugin Name: VidSrc PsyPlay
Plugin URI: https://vidsrc.me
Description: Automating PsyPlay to add and update latest streams From VidSrc.me.
Author: VidSrc
Version: 1.0
Author URI: https://vidsrc.me
*/





ini_set("memory_limit","2048M"); 

$script_start = microtime(true);
$script_limit = 60;

function update_all_episodes(){
    $sql = "
    UPDATE wp_vidsrc
    LEFT JOIN (
    	SELECT 
        	posts.ID as post_id ,
            tmdb.meta_value as tmdb ,
            season.meta_value as season , 
            episode.meta_value as episode
        FROM wp_posts as posts
        LEFT JOIN wp_postmeta as tmdb 
        ON	tmdb.meta_key = 'ids' AND 
            tmdb.post_id = posts.ID
        LEFT JOIN wp_postmeta as season 
        ON	season.meta_key = 'temporada' AND 
            season.post_id = posts.ID
        LEFT JOIN wp_postmeta as episode 
        ON	episode.meta_key = 'episodio' AND 
            episode.post_id = posts.ID
        WHERE 
            posts.post_type = 'episodes'
        GROUP BY posts.ID
    ) as episodes 
    ON	episodes.tmdb = wp_vidsrc.tmdb AND 
    	episodes.season = wp_vidsrc.season AND 
        episodes.episode = wp_vidsrc.episode
    SET	wp_vidsrc.post_id = episodes.post_id
    WHERE 
    	wp_vidsrc.post_id IS NULL AND 
        wp_vidsrc.season IS NOT NULL AND 
        episodes.post_id IS NOT NULL
    ";
}

    
function checkLoad(){
    if(function_exists("sys_getloadavg")){
        $load = sys_getloadavg();    
        if($load[0] > 8){
            return 0;
        }else{
            return 1;
        }
    }else{
        return 1;
    }
}




function vidsrc_insert_sql($table , $cols , $vals){
    $sql = "
    INSERT INTO ".$table."
    (".$cols.")
    VALUES
    ";
    if(is_array($vals)){
        $sql .= implode(",\n" ,$vals);
    }else{
        $sql .= $vals;
    }
    
    $sql .= "
    ON DUPLICATE KEY UPDATE
        id = id
    ";
    
    return $sql;
}

function vidsrc_get_indb_info(){
    global $wpdb;


    $indb_mov_num = $wpdb->get_var("
        SELECT 
            COUNT(*) 
        FROM ".$wpdb->prefix."vidsrc
        WHERE 
            season IS NULL
        ");
    $indb_eps_num = $wpdb->get_var("
        SELECT 
            COUNT(*) 
        FROM ".$wpdb->prefix."vidsrc as vidsrc
        WHERE 
            season IS NOT NULL
        ");
        
    $info = new stdClass();
    $info->num_mov = $indb_mov_num;
    $info->num_eps = $indb_eps_num;
    $info->num_all = $indb_mov_num+$indb_eps_num;
    
    return $info;
}


function vidsrc_get_sync_info(){
    $all_vidsrc_info = curl_get_data('https://v2.vidsrc.me/api/all/info');
	$all_vidsrc_info = json_decode($all_vidsrc_info);
	$all_vidsrc_indb_info = vidsrc_get_indb_info();
	
	$vidsrc_percent = round(($all_vidsrc_indb_info->num_all/$all_vidsrc_info->num_all),2)*100;
	
	
	
	$done_percent = round($vidsrc_percent,2);
	
	
	return $done_percent;
}


function vidsrc_insert_data($page = 0 , $tv = 0){
    global $wpdb;
    
    ini_set("memory_limit","1024M"); 
    
    if($tv)
        $url = 'https://v2.vidsrc.me/api/e/l';
    else
        $url = 'https://v2.vidsrc.me/api/m/l';
    
    if(@$page > 0){
        $url .= "/page-".$page;
    }
    
    
    $all_data = curl_get_data($url);
    $all_data = json_decode($all_data);
    
    
    $new_all_data = [];
    foreach($all_data as $row){
        if($tv)
            $new_all_data[$row->imdb_id."_".$row->season."x".$row->episode] = $row;
        else
            $new_all_data[$row->imdb_id] = $row;
    }
    $all_data = $new_all_data;
    unset($new_all_data);
    
    if($tv){
        $indb_all_data = $wpdb->get_results("
            SELECT 
                CONCAT(imdb ,'_' ,season,'x' ,episode) as unique_key
            FROM ".$wpdb->prefix."vidsrc as vidsrc
            WHERE 
                season IS NOT NULL
        ");	
    }else{
        $indb_all_data = $wpdb->get_results("
            SELECT 
                imdb as unique_key
            FROM ".$wpdb->prefix."vidsrc as vidsrc
            WHERE 
                season IS NULL
        ");	
    }
    
    $new_indb_all_data = [];
    foreach($indb_all_data as $row){
        $new_indb_all_data[$row->unique_key] = "";
    }
    $indb_all_data = $new_indb_all_data;

    $insert_eps_data = array_diff_key($all_data,$indb_all_data);
    
    //print_r($all_data);
    //print_r($indb_all_data);
    //print_r($insert_eps_data);
    //exit();
    
    if(!empty($insert_eps_data)){
        $table = $wpdb->prefix."vidsrc";
        if($tv)
            $cols = "imdb , tmdb , season , episode , quality , last_update";
        else
            $cols = "imdb , quality , last_update";
            
        $values = [];
        $tmp_vals = [];
        $br = 0;
        $insert_count = 0;
        foreach($insert_eps_data as $ep_data){
            if($tv){
                $tmp_vals[] = "('".$ep_data->imdb_id."','".$ep_data->tmdb."','".$ep_data->season."','".$ep_data->episode."','".$ep_data->quality."','".time()."')";
            }else{
                $tmp_vals[] = "('".$ep_data->imdb_id."','".$ep_data->quality."','".time()."')";
            }
            $br++;
            $insert_count++;
            if($br >= 1000){
                $br = 0;
                $values[] = $tmp_vals;
                $tmp_vals = [];
            }
            
            if($insert_count >= 49900){
                break;
            }
        }
        
        if(!empty($tmp_vals)){
            $values[] = $tmp_vals;
            unset($tmp_vals);
        }
        
        foreach($values as $vals){
            $sql = vidsrc_insert_sql($table , $cols , $vals);
            $wpdb->query($sql);
            if($wpdb->last_error !== ''){
                $wpdb->print_error();
                exit();
            }
        }
        
        return count($insert_eps_data);
    }
}




function vidsrc_add_data_latest(){
    // tv
    $tv = 1;
    $page = 1;
    vidsrc_insert_data($page , $tv);
    $indb_info = vidsrc_get_indb_info();
    
    
    // mov
    $tv = 0;
    $page = 1;
    vidsrc_insert_data($page , $tv);
    $indb_info = vidsrc_get_indb_info();
}


function is_synced(){
    if(get_option("vidsrc_sync") == 1 )
        return true;
    else
        return false;
    
}

$vidsrc_mov_file = dirname(__FILE__)."/vidsrc_mov.txt";
$vidsrc_eps_file = dirname(__FILE__)."/vidsrc_eps.txt";

function vidsrc_add_data(){
    
    global $vidsrc_mov_file;
    global $vidsrc_eps_file;
    
    $min_15 = 60*1;
    
    
    
	if(file_exists($vidsrc_mov_file) && filesize($vidsrc_mov_file)){
	    if(time()-filemtime($vidsrc_mov_file) > $min_15){
    	    $vidsrc_mov = file_get_contents("https://v2.vidsrc.me/ids/mov.txt");
    	    //echo "mov";
    	    //echo time()-filemtime($vidsrc_mov_file)."-".$min_15;
	    }
	}else{
	    $vidsrc_mov = file_get_contents("https://v2.vidsrc.me/ids/mov.txt");
	}
    
    
    if(file_exists($vidsrc_eps_file) && filesize($vidsrc_eps_file)){
	    if(time()-filemtime($vidsrc_eps_file) > $min_15){
    	    $vidsrc_eps = file_get_contents("https://v2.vidsrc.me/ids/eps.txt");
    	    //echo "mov";
    	    //echo time()-filemtime($vidsrc_eps_file)."-".$min_15;
	    }
	}else{
	    $vidsrc_eps = file_get_contents("https://v2.vidsrc.me/ids/eps.txt");
	}
    
    
    if(@strlen($vidsrc_mov)){
        file_put_contents($vidsrc_mov_file , $vidsrc_mov);
    }
    
    if(@strlen($vidsrc_eps)){
        file_put_contents($vidsrc_eps_file , $vidsrc_eps);
    }
    
    
    return;
    exit();
    
    $vidsrc_sync = get_option("vidsrc_sync");
    if($vidsrc_sync == 1){
        return 0;
    }
    
    
    
    if(intval($vidsrc_sync) != 0)
        update_option('vidsrc_sync',"0");
        
    
    
    
    $all_info = curl_get_data('https://v2.vidsrc.me/api/all/info');
	$all_info_data = json_decode($all_info);
	
	
	$indb_info = vidsrc_get_indb_info();
    
    
    if( ($all_info_data->num_eps-100) > $indb_info->num_eps || 
        ($all_info_data->num_mov-100) > $indb_info->num_mov
        ){
            
        if($all_info_data->num_eps > $indb_info->num_eps){
            $tv = 1;
            if($all_info_data->num_eps - $indb_info->num_eps > 100){
                vidsrc_insert_data(0 , $tv);
            }else{
                $page = 1;
                vidsrc_insert_data($page , $tv);
                $indb_info = vidsrc_get_indb_info();
                
                
                if($all_info_data->num_eps > $indb_info->num_eps){
                    vidsrc_insert_data(0 , $tv);
                }
            }
        }
        
        if($all_info_data->num_mov > $indb_info->num_mov){
            $tv = 0;
            if($all_info_data->num_mov - $indb_info->num_mov > 100){
                vidsrc_insert_data(0 , $tv);
            }else{
                $page = 1;
                vidsrc_insert_data($page , $tv);
                $indb_info = vidsrc_get_indb_info();
                
                
                if($all_info_data->num_mov > $indb_info->num_mov){
                    vidsrc_insert_data(0 , $tv);
                }
            }
        }
        
    }else{
        $vidsrc_sync = '1';
        update_option('vidsrc_sync',$vidsrc_sync);
    }
}



$indb_mov_file = dirname(__FILE__)."/indb_mov.txt";
$indb_eps_file = dirname(__FILE__)."/indb_eps.txt";



function vidsrc_add_data_indb(){
	
    ini_set('max_execution_time', '120');
    
    global $wpdb;
	global $indb_mov_file;
	global $indb_eps_file;
    
	$sql_indb_eps = "
	SELECT 
    	CONCAT(tmdb.meta_value,'_',season.meta_value ,'x',episode.meta_value) as ep
    FROM $wpdb->posts as posts
    LEFT JOIN $wpdb->postmeta as tmdb ON
       	tmdb.post_id = posts.ID AND
    	tmdb.meta_key = 'ids'
    LEFT JOIN $wpdb->postmeta as season ON
       	season.post_id = posts.ID AND
    	season.meta_key = 'temporada'
    LEFT JOIN $wpdb->postmeta as episode ON
       	episode.post_id = posts.ID AND
    	episode.meta_key = 'episodio'
    WHERE 
    	posts.post_type = 'episodes' AND 
        posts.post_status = 'publish' AND
        episode.meta_id IS NOT NULL
	";
	
	$sql_indb_mov = "
	SELECT 
    	imdb.meta_value as imdb
    FROM $wpdb->posts as posts
    LEFT JOIN $wpdb->postmeta as imdb ON
       	imdb.post_id = posts.ID AND
    	imdb.meta_key = 'Checkbx2'
    WHERE 
    	posts.post_type = 'post' AND
        posts.post_status = 'publish' AND
    	imdb.meta_id IS NOT NULL
	";
	
	
	$day = 3600*24;
	
	
	$flag_eps = 1;
	$flag_mov = 1;
	
	if(file_exists($indb_mov_file) && time()-filemtime($indb_mov_file) < $day){
	    $flag_mov = 0;
	}
	if(file_exists($indb_eps_file) && time()-filemtime($indb_eps_file) < $day){
	    $flag_mov = 0;
	}
	
	if($flag_mov){
	    $indb_mov_ids = [];
        
        $res_mov = $wpdb->get_results($sql_indb_mov);
        
        foreach($res_mov as $row){
            $indb_mov_ids[] = $row->imdb;
        }
        
        unset($res_mov);
        file_put_contents($indb_mov_file , implode("\n",$indb_mov_ids));
	}
	
	if($flag_mov){
	    $indb_eps_ids = [];
    
        $res_eps = $wpdb->get_results($sql_indb_eps);
        
        foreach($res_eps as $row){
            $indb_eps_ids[] = $row->ep;
        }
        
        unset($res_eps);
        file_put_contents($indb_eps_file , implode("\n",$indb_eps_ids));
	}
	
	
	return;
	
	exit();
	
    
    $sql_set_movs_indb = "
    UPDATE ".$wpdb->prefix."vidsrc
    LEFT JOIN $wpdb->postmeta as postmeta_imdb 
    ON	postmeta_imdb.meta_key = 'Checkbx2' AND 
    	postmeta_imdb.meta_value = wp_vidsrc.imdb
    LEFT JOIN $wpdb->posts as posts
    ON  postmeta_imdb.post_id = posts.ID AND
        posts.post_status = 'publish'
    SET	wp_vidsrc.post_id = postmeta_imdb.post_id
    WHERE   
    	postmeta_imdb.meta_value IS NOT NULL
    ";
    
    $wpdb->query($sql_set_movs_indb);
    
    $sql_eps_c_indb_not_set = "
    SELECT 
    	COUNT(1) as c
    FROM $wpdb->postmeta as postmeta_episode
    LEFT JOIN	
    	$wpdb->postmeta as postmeta_tmdb
    ON	postmeta_tmdb.post_id = postmeta_episode.post_id AND
    	postmeta_tmdb.meta_key = 'ids'
    LEFT JOIN	
    	$wpdb->postmeta as postmeta_season
    ON	postmeta_season.post_id = postmeta_episode.post_id AND
    	postmeta_season.meta_key = 'temporada'
    LEFT JOIN $wpdb->posts as posts
    ON	posts.ID = postmeta_episode.post_id AND
    	posts.post_status = 'publish' 
    LEFT JOIN ".$wpdb->prefix."vidsrc as vidsrc 
    ON	vidsrc.tmdb = postmeta_tmdb.meta_value AND
		vidsrc.season = postmeta_season.meta_value AND 
		vidsrc.episode = postmeta_episode.meta_value
    WHERE
    	postmeta_episode.meta_key = 'episodio' AND
        posts.ID IS NOT NULL AND 
        vidsrc.post_id IS NULL AND 
		vidsrc.id IS NOT NULL
    "; 
    
    $sql_set_eps_indb = "
    UPDATE ".$wpdb->prefix."vidsrc as vidsrc 
    LEFT JOIN (
    		SELECT 
        	vidsrc.id , 
    		postmeta_episode.post_id
        FROM $wpdb->postmeta as postmeta_episode
        LEFT JOIN	
        	$wpdb->postmeta as postmeta_tmdb
        ON	postmeta_tmdb.post_id = postmeta_episode.post_id AND
        	postmeta_tmdb.meta_key = 'ids'
        LEFT JOIN	
        	$wpdb->postmeta as postmeta_season
        ON	postmeta_season.post_id = postmeta_episode.post_id AND
        	postmeta_season.meta_key = 'temporada'
        LEFT JOIN $wpdb->posts as posts
        ON	posts.ID = postmeta_episode.post_id AND
        	posts.post_status = 'publish' 
        LEFT JOIN ".$wpdb->prefix."vidsrc as vidsrc 
        ON	vidsrc.tmdb = postmeta_tmdb.meta_value AND
    		vidsrc.season = postmeta_season.meta_value AND 
    		vidsrc.episode = postmeta_episode.meta_value
        WHERE
        	postmeta_episode.meta_key = 'episodio' AND
            posts.ID IS NOT NULL AND 
            vidsrc.post_id IS NULL AND 
    		vidsrc.id IS NOT NULL
    	) as eps_indb
    ON	eps_indb.id = vidsrc.id 
    SET vidsrc.post_id = eps_indb.post_id
    WHERE 
    	eps_indb.post_id IS NOT NULL
    ";
    
    if($wpdb->get_var($sql_eps_c_indb_not_set) > 1000){
        $wpdb->query($sql_set_eps_indb);
    }
    
    return;
    /*
    
	UPDATE ".$wpdb->prefix."vidsrc
    LEFT JOIN $wpdb->postmeta as postmeta_imdb 
    ON	postmeta_imdb.meta_key = 'Checkbx2' AND 
    	postmeta_imdb.meta_value = wp_vidsrc.imdb
    LEFT JOIN $wpdb->posts as posts
    ON  postmeta_imdb.post_id = posts.ID AND
        posts.post_status = 'publish'
    SET	wp_vidsrc.post_id = postmeta_imdb.post_id
    WHERE   
    	postmeta_imdb.meta_value IS NOT NULL
    
    SELECT 
    	COUNT(1) as c
    FROM $wpdb->postmeta as postmeta_episode
    LEFT JOIN	
    	$wpdb->postmeta as postmeta_tmdb
    ON	postmeta_tmdb.post_id = postmeta_episode.post_id AND
    	postmeta_tmdb.meta_key = 'ids'
    LEFT JOIN	
    	$wpdb->postmeta as postmeta_season
    ON	postmeta_season.post_id = postmeta_episode.post_id AND
    	postmeta_season.meta_key = 'temporada'
    LEFT JOIN $wpdb->posts as posts
    ON	posts.ID = postmeta_episode.post_id AND
    	posts.post_status = 'publish' 
    LEFT JOIN ".$wpdb->prefix."vidsrc as vidsrc 
    ON	vidsrc.tmdb = postmeta_tmdb.meta_value AND
		vidsrc.season = postmeta_season.meta_value AND 
		vidsrc.episode = postmeta_episode.meta_value
    WHERE
    	postmeta_episode.meta_key = 'episodio' AND
        posts.ID IS NOT NULL AND 
        vidsrc.post_id IS NULL AND 
		vidsrc.id IS NOT NULL
		
    
    
    UPDATE ".$wpdb->prefix."vidsrc as vidsrc 
    LEFT JOIN (
    		SELECT 
        	vidsrc.id , 
    		postmeta_episode.post_id
        FROM $wpdb->postmeta as postmeta_episode
        LEFT JOIN	
        	$wpdb->postmeta as postmeta_tmdb
        ON	postmeta_tmdb.post_id = postmeta_episode.post_id AND
        	postmeta_tmdb.meta_key = 'ids'
        LEFT JOIN	
        	$wpdb->postmeta as postmeta_season
        ON	postmeta_season.post_id = postmeta_episode.post_id AND
        	postmeta_season.meta_key = 'temporada'
        LEFT JOIN $wpdb->posts as posts
        ON	posts.ID = postmeta_episode.post_id AND
        	posts.post_status = 'publish' 
        LEFT JOIN ".$wpdb->prefix."vidsrc as vidsrc 
        ON	vidsrc.tmdb = postmeta_tmdb.meta_value AND
    		vidsrc.season = postmeta_season.meta_value AND 
    		vidsrc.episode = postmeta_episode.meta_value
        WHERE
        	postmeta_episode.meta_key = 'episodio' AND
            posts.ID IS NOT NULL AND 
            vidsrc.post_id IS NULL AND 
    		vidsrc.id IS NOT NULL
    	) as eps_indb
    ON	eps_indb.id = vidsrc.id 
    SET vidsrc.post_id = eps_indb.post_id
    WHERE 
    	eps_indb.post_id IS NOT NULL
    	
    	
    
    $indb_all_movs_num = get_all_movies(0 , 1);
    $indb_all_eps_num = get_all_eps(0 , 1);
    
    global $wpdb;
    
    $vidsrc_indb_all_movs = $wpdb->get_var("
        SELECT COUNT(*) FROM 
            ".$wpdb->prefix."vidsrc_indb
        WHERE
            season IS NULL
        ");
    $vidsrc_indb_all_eps = $wpdb->get_var("
        SELECT COUNT(*) FROM 
            ".$wpdb->prefix."vidsrc_indb
        WHERE
            season IS NOT NULL
        ");
        
	
    if( ($indb_all_movs_num-100) > $vidsrc_indb_all_movs ||
        ($indb_all_eps_num-100) > $vidsrc_indb_all_eps
        ){
		
        if(($indb_all_movs_num-100) > $vidsrc_indb_all_movs){
            ini_set("memory_limit","1024M");
            $tv = 0;
            $movs = get_all_movies(0 , 0 , 1);
            
            vidsrc_insert_indb_data($tv , $movs);   
        }
		
        if(($indb_all_eps_num-100) > $vidsrc_indb_all_eps){
            ini_set("memory_limit","1024M");
            $tv = 1;
            $eps = get_all_eps(0 , 0 , 1);
			
            vidsrc_insert_indb_data($tv , $eps);   
        }
    }else{
        $vidsrc_sync = 1;
        update_option('vidsrc_sync_indb',$vidsrc_sync);
    }
    
    */
}

function vidsrc_insert_indb_data($tv , $rows){
    global $wpdb;
    
    if(!empty($rows)){
        $table = $wpdb->prefix."vidsrc_indb";
        if($tv)
            $cols = "post_id , tmdb , season , episode";
        else
            $cols = "post_id , imdb ";
            
        $values = [];
        $tmp_vals = [];
        $br = 0;
        $insert_count = 0;
        foreach($rows as $key => $post_id){
            if(@is_numeric($post_id)){
				if($tv){
					preg_match("/([0-9]+)_([0-9]+)_([0-9]+)/" , $key , $match);
					if($match){
						$ep_data = new stdClass();
						$ep_data->tmdb = $match[1];
						$ep_data->season = $match[2];
						$ep_data->episode = $match[3];


						$tmp_vals[] = "('".$post_id."','".$ep_data->tmdb."','".$ep_data->season."','".$ep_data->episode."')";
					}
				}else{
					$tmp_vals[] = "('".$post_id."','".$key."')";
				}
				$br++;
				$insert_count++;
				if($br >= 1000){
					$br = 0;
					$values[] = $tmp_vals;
					$tmp_vals = [];
				}

				if($insert_count >= 49900){
					break;
				}
			}
            
        }
        
        if(!empty($tmp_vals)){
            $values[] = $tmp_vals;
            unset($tmp_vals);
        }
        
        
        foreach($values as $vals){
			
            $sql = vidsrc_insert_sql($table , $cols , $vals);
            $wpdb->query($sql);
            
            if($wpdb->last_error !== ''){
                $wpdb->print_error();
                exit($wpdb->last_error);
            }
        }
    }
}



function vidsrc_insert_indb_row($post_id , $data){
    global $wpdb;
    global $indb_mov_file;
    global $indb_eps_file;
    
    if(@is_numeric($data->tmdb) && @is_numeric($data->season) && @is_numeric($data->episode)){
        $indb_id = $data->tmdb."_".$data->season."x".$data->episode;
        file_put_contents($indb_eps_file , "\n".$indb_id , FILE_APPEND | LOCK_EX);
    }else{
        $indb_id = $data->imdb_id;
        file_put_contents($indb_mov_file , "\n".$indb_id , FILE_APPEND | LOCK_EX);
    }
}



function count_deleted_dupl($dupl){
    if(is_array($dupl)){
        $sum = 0;
        foreach($dupl as $num){
            $sum += $num;
        }
        
        return $sum;
    }else{
        return 0;
    }
}


function vidsrc_clean_dupl(){
    
    if(!checkLoad())
        return 0;


    global $wpdb;
    
    $dupl = [];
    
    $dupl['movs'] = 0;
    $dupl['tvs'] = 0;
    $dupl['ses'] = 0;
    $dupl['eps'] = 0;
    
    $limit_delete = 30;
    
    $mov_dupl_res = $wpdb->get_results("
    SELECT 
    	GROUP_CONCAT(DISTINCT wp_posts.ID) as ids ,
        COUNT(*) as c
    FROM $wpdb->posts as wp_posts 
    LEFT JOIN $wpdb->postmeta as imdb ON
    	imdb.post_id = wp_posts.ID AND
        imdb.meta_key = 'Checkbx2'
    WHERE
    	wp_posts.post_type = 'post' AND
        wp_posts.post_status = 'publish'
    GROUP by imdb.meta_value
    HAVING c>1
    LIMIT 30");	
    
    
    foreach($mov_dupl_res as $mov_dupl_row){
        $ids = explode("," , $mov_dupl_row->ids);
        rsort($ids);
        $delete_id = $ids[0];
        if(!wp_delete_post($delete_id , true)){
            echo "failed delete: ".$delete_id."</br>\n";
            exit();
        }else{
            $dupl['movs']++;
            if(count_deleted_dupl($dupl) >= $limit_delete)
                exit();
        }
    }
    
    $tv_dupl_res = $wpdb->get_results(
    "SELECT 
    	GROUP_CONCAT(DISTINCT wp_posts.ID) as ids ,
        COUNT(*) as c
    FROM $wpdb->posts as wp_posts 
    LEFT JOIN $wpdb->postmeta as tmdb ON
    	tmdb.post_id = wp_posts.ID AND
        tmdb.meta_key = 'id'
    WHERE
    	wp_posts.post_type = 'tvshows' AND
        wp_posts.post_status = 'publish'
    GROUP by tmdb.meta_value
    HAVING c>1
    LIMIT 30");	
    
    foreach($tv_dupl_res as $tv_dupl_row){
        $ids = explode("," , $tv_dupl_row->ids);
        rsort($ids);
        $delete_id = $ids[0];
        if(!wp_delete_post($delete_id , true)){
            echo "failed delete: ".$delete_id."</br>\n";
            exit();
        }else{
            $dupl['tvs']++;
            if(count_deleted_dupl($dupl) >= $limit_delete)
                exit();
        }
    }
    
    
    
    
    $ep_dupl_res = $wpdb->get_results("
    SELECT 
    	GROUP_CONCAT(DISTINCT ID) as ids , 
        sep ,
        COUNT(*) as c
    FROM (
    SELECT 
    	wp_posts.ID , 
        CONCAT(tmdb.meta_value , '_' , season.meta_value , 'x' , episode.meta_value) as sep
    FROM $wpdb->posts as wp_posts 
    LEFT JOIN $wpdb->postmeta as tmdb ON
    	tmdb.post_id = wp_posts.ID AND
        tmdb.meta_key = 'ids'
    LEFT JOIN $wpdb->postmeta as season ON
    	season.post_id = wp_posts.ID AND
        season.meta_key = 'temporada'
    LEFT JOIN $wpdb->postmeta as episode ON
    	episode.post_id = wp_posts.ID AND
        episode.meta_key = 'episodio'
    WHERE
    	wp_posts.post_type = 'episodes' AND
        wp_posts.post_status = 'publish'
    ) as episodes
    GROUP by sep
    HAVING c>1
    LIMIT 30");
    
    
    foreach($ep_dupl_res as $ep_dupl_row){
        $ids = explode("," , $ep_dupl_row->ids);
        rsort($ids);
        for($i = 0;$i<count($ids)-1;$i++){
            $delete_id = $ids[$i];
            if(!wp_delete_post($delete_id , true)){
                echo "failed delete: ".$delete_id."</br>\n";
                exit();
            }else{
                $dupl['eps']++;
                if(count_deleted_dupl($dupl) >= $limit_delete)
                    exit();
            }
        }
    }
    
    echo $dupl['movs']." duplicate movies cleaned</br>\n";
    echo $dupl['tvs']." duplicate tv shows cleaned</br>\n";
    echo $dupl['eps']." duplicate episodes cleaned</br>\n";
    
    
}



function updatePlayerColor(){
    if(!is_synced()){
        return 0;
    }
    
    if(!checkLoad())
        return 0;
        
    global $wpdb;
    
    $player_color = getPlayerColor();
    
    if(@strlen($player_color)){
    
        $wpdb->get_row("
            UPDATE $wpdb->postmeta 
            SET 
                meta_value = REGEXP_REPLACE(meta_value,'color-([a-z0-9]{0,6})','".$player_color."')
            WHERE
            	meta_value LIKE '%vidsrc.me/embed%' AND
                meta_value LIKE '%/color-%' AND
                meta_value NOT LIKE '%/".$player_color."%'
        ");
        
        $wpdb->get_row("
            UPDATE $wpdb->postmeta
            SET meta_value = CONCAT(meta_value,'".$player_color."')
            WHERE
            	meta_value REGEXP 'vidsrc.me\/embed(.*)\/$' AND
                meta_value NOT LIKE '%/color-%'
        ");
    
    }
}

function getPlayerColor(){
    
    $player_color_db = get_option("vidsrc_player_color");
    
    
    if(strlen($player_color_db) == 7){
        return "color-".str_replace("#" , "" , $player_color_db);
    }else{
        $colors = [
            "orange"    => "f3702f",
            "green"     => "79c143",
            "blue"      => "0590cc",
            "purple"    => "9e39e8",
            "pink"      => "e45cc0",
            "red"       => "ca2929"
            ];
        
        global $wpdb;
        
        $color_row = $wpdb->get_row("
            SELECT * FROM $wpdb->options 
            WHERE option_name LIKE 'psy-color-scheme'
        ");
        
        if( isset($colors[$color_row->option_value]) ){
            return "color-".$colors[$color_row->option_value];
        }else{
            return "";
        }
    }
    
}




function vidsrc_clean_dead_titles(){
    if(!is_synced()){
        return 0;
    }
    
    if(!checkLoad())
        return 0;
        
        
    ini_set("memory_limit","1024M");
    
    $delete_limit = 30;
    $delete_c = 0;
    
    $indb_all_vidsrc_movies = get_all_movies(1);
    $vidsrc_all_m_data = curl_get_data('https://v2.vidsrc.me/api/m/l');
	$vidsrc_all_m_data = json_decode($vidsrc_all_m_data);
	
	if(@count($vidsrc_all_m_data) > 1000){
    	$vidsrc_tmp = [];
    	foreach($vidsrc_all_m_data as $vidsrc_mov){
    	    $vidsrc_tmp[$vidsrc_mov->imdb_id] = "";
    	}
    	
    	
    	$vidsrc_all_m_data = $vidsrc_tmp;
    	unset($vidsrc_tmp);
    	
    	$dead_movies = [];
    	if(is_array($vidsrc_all_m_data)){
    	    foreach($indb_all_vidsrc_movies as $key => $val){
                if(!isset($vidsrc_all_m_data[$key])){
                    $dead_movies[$key] = $val;
    	        }
    	    }
    	}
    	
    	foreach($dead_movies as $delete_id){
    	    if(!wp_delete_post($delete_id , true)){
                echo "failed delete: ".$delete_id."</br>\n";
                exit();
            }else{
                $delete_c++;
            }
            if($delete_c >= $delete_limit)
                break;
    	}
    	
    	
    	unset($vidsrc_all_m_data);
    	unset($indb_all_vidsrc_movies);
	
	}else{
	    exit();
	}
	
	
	$indb_all_vidsrc_eps = get_all_eps(1);
	$vidsrc_all_eps_data = curl_get_data('https://v2.vidsrc.me/api/e/l');
	$vidsrc_all_eps_data = json_decode($vidsrc_all_eps_data);
	
	if(@count($vidsrc_all_eps_data) > 1000){
    	$vidsrc_tmp = [];
    	$vidsrc_all_tv_data = [];
    	foreach($vidsrc_all_eps_data as $vidsrc_ep){
    	    $vidsrc_tmp[$vidsrc_ep->tmdb."_".$vidsrc_ep->season."_".$vidsrc_ep->episode] = "";
    	    if(!isset($vidsrc_all_tv_data[$vidsrc_ep->tmdb]))
    	        $vidsrc_all_tv_data[$vidsrc_ep->tmdb] = "";
    	}
    	
    	$vidsrc_all_eps_data = $vidsrc_tmp;
    	unset($vidsrc_tmp);
    	$dead_eps = [];
    	if(is_array($vidsrc_all_eps_data)){
    	    foreach($indb_all_vidsrc_eps as $key => $val){
                if(!isset($vidsrc_all_eps_data[$key])){
                    $dead_eps[$key] = $val;
    	        }
    	    }
    	}
    	
    	unset($vidsrc_all_eps_data);
    	unset($indb_all_vidsrc_eps);
    	
    	
    	foreach($dead_eps as $delete_id){
    	    if(!wp_delete_post($delete_id , true)){
                echo "failed delete: ".$delete_id."</br>\n";
                exit();
            }else{
                $delete_c++;
            }
            if($delete_c >= $delete_limit)
                break;
    	}
	
        $indb_all_vidsrc_tvs = get_all_tvs(1);
        
        $dead_tvs = [];
    	if(is_array($vidsrc_all_tv_data)){
    	    foreach($indb_all_vidsrc_tvs as $key => $val){
                if(!isset($vidsrc_all_tv_data[$key])){
                    $dead_tvs[$key] = $val;
    	        }
    	    }
    	}
    	
    	
    	unset($vidsrc_all_tv_data);
    	unset($indb_all_vidsrc_tvs);
    	
    	foreach($dead_tvs as $delete_id){
    	    if(!wp_delete_post($delete_id , true)){
                echo "failed delete: ".$delete_id."</br>\n";
                exit();
            }else{
                $delete_c++;
            }
            if($delete_c >= $delete_limit)
                break;
    	}
    	
	
	}else{
	    exit();
	}
}


$ep_add_limit;
$ep_add_count;
$eps_not_added_gl;





function vidsrc_do_action_for_tvshows(){
    
    
    
    if(!checkLoad())
            return 0;
    
    global $ep_add_limit;
    global $ep_add_count;
    global $eps_not_added_gl;
    
    
    $ep_add_limit = cron_add_limit('e');
    $ep_add_count = 0;
    
    

	if(get_option('vidsrc_cron') != 'off' && get_option('vidsrc_active')){
	    
    
	    
	    $eps_not_added_gl = get_episodes_not_added();
	    
	    
	    $new_eps_not_added_gl = [];
	    foreach($eps_not_added_gl as $ep){
	        $ep_key = $ep->tmdb."_".$ep->season."_".$ep->episode;
	        $new_eps_not_added_gl[$ep_key] = $ep;
	    }
	    
	    $eps_not_added_gl = $new_eps_not_added_gl;
	    unset($new_eps_not_added_gl);
	    
	    
	    if(!empty($eps_not_added_gl)){
            foreach($eps_not_added_gl as $vidsrc_ep){
                vidsrc_post_episodes($vidsrc_ep);
            }
	    }
	    
	    return;
	    $eps_not_updated = get_episodes_not_updated();
	    if(!empty($eps_not_updated)){
	        foreach($eps_not_updated as $vidsrc_ep){
	                if(vidsrc_update_episode($vidsrc_ep)){
	                    $ep_add_count++;
	                    if($ep_add_count >= $ep_add_limit)
	                        exit("limit reached");
	                }
	        }
	    }
	    
	}
    return 0;
	
	
	
}


function vidsrcRandomStr($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';//ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function vidsrc_random_strings($vidsrc_length = 4){
		$vidsrc_characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; $vidsrc_charactersLength = strlen($vidsrc_characters); $vidsrc_randomString = ''; for ($vidsrc_i = 0; $vidsrc_i < $vidsrc_length; $vidsrc_i++) {$vidsrc_randomString .= $vidsrc_characters[rand(0, $vidsrc_charactersLength - 1)]; } return $vidsrc_randomString;
	}



    
$movies_add_limit;
$movies_not_added;


function vidsrc_do_action_for_movies() {
    global $script_start;
    global $script_limit;
    
    if(!checkLoad())
        return 0;
        
        
	
    
	if(get_option('vidsrc_cron') != 'off' && get_option('vidsrc_active')){

    	   
        global $movies_add_limit;
    	$movies_add_limit = cron_add_limit('m');
    	$movies_not_added = get_movies_not_added();
    	
    	$add_count = 0;
    	
        
		if(!empty($movies_not_added)){
    	    foreach($movies_not_added as $vidsrc_m){
    	        $post_movie_res = vidsrc_post_movie($vidsrc_m);
                if( $post_movie_res == 'insert' || 
                    $post_movie_res == 'update' ){
    				$add_count++;
    				if($add_count >= $movies_add_limit) 
    				    exit;
    				
                    if(microtime(true)-$script_start > $script_limit)
                        exit();
    			}
    	    } 
		}
	            
        return;
    	$movies_not_updated = get_movies_not_updated();
		if(!empty($movies_not_updated)){
		    foreach($movies_not_updated as $vidsrc_m){
				if(vidsrc_post_movie($vidsrc_m) == 'update'){
					$add_count++;
					if($add_count >= $movies_add_limit){ exit; }
				}	
		    }
		}
		
		
		
 	}
}


function get_all_movies($by_vidsrc = 0 , $count = 0 , $not_indb = 0){
    
    global $wpdb;
    
    
    
    $limit = "";
    if($not_indb){
        $not_indb_sql_join = "
        LEFT JOIN ".$wpdb->prefix."vidsrc_indb as vidsrc_indb
        ON  posts.ID = vidsrc_indb.post_id
        ";
        $not_indb_sql_where = "
        AND vidsrc_indb.post_id IS NULL
        ";
        $limit = "
        LIMIT 0 , 10000";
    }
    
    if($by_vidsrc)
        $by_vidsrc_sql = "AND \n posts.post_author = 333";
    
    if(!$count){
        $sql = "
        SELECT  
            postmeta_imdb.meta_value as imdb_id ,
            posts.ID
        FROM 
            $wpdb->postmeta as postmeta_imdb
        LEFT JOIN $wpdb->posts as posts
        ON  postmeta_imdb.post_id = posts.ID AND
            posts.post_status = 'publish' $by_vidsrc_sql
        $not_indb_sql_join
        WHERE   
        	postmeta_imdb.meta_key = 'Checkbx2'
        	$not_indb_sql_where
        $limit
        ";
    }
    
    if($count){
        $sql = "
        SELECT  
            COUNT(*)
        FROM 
            $wpdb->posts as posts
        WHERE
            posts.post_type = 'post' AND
            posts.post_status = 'publish' $by_vidsrc_sql
        ";
    }
    
    
    
    
    if($count){
        return $wpdb->get_var($sql);
    }else{
        $imdb_rows = $wpdb->get_results($sql);
        
        $all_imdb_arr = [];
        
        foreach($imdb_rows as $imdb_row){
            $all_imdb_arr[$imdb_row->imdb_id] = $imdb_row->ID;
        }
                    
        return $all_imdb_arr;
    }
}





function get_all_eps($by_vidsrc = 0 , $count = 0 , $not_indb = 0){
    global $wpdb;
    
    
    if($not_indb){
        $not_indb_sql_join = "
        LEFT JOIN ".$wpdb->prefix."vidsrc_indb as vidsrc_indb
        ON  posts.ID = vidsrc_indb.post_id
        ";
        $not_indb_sql_where = "
        AND vidsrc_indb.post_id IS NULL
        ";
        $limit = "
        LIMIT 0 , 10000";
        $select = "
            
        ";
    }
    
    if($by_vidsrc)
        $by_vidsrc_sql = "AND \n posts.post_author = 333";
    
    if(!$count){
        $sql = "
        SELECT 
        	postmeta_tmdb.meta_value as tmdb_id ,
            postmeta_season.meta_value as season ,
            postmeta_episode.meta_value as episode ,
            postmeta_episode.post_id as ID
        FROM $wpdb->postmeta as postmeta_episode
        LEFT JOIN	
        	$wpdb->postmeta as postmeta_tmdb
        ON	postmeta_tmdb.post_id = postmeta_episode.post_id AND
        	postmeta_tmdb.meta_key = 'ids'
        LEFT JOIN	
        	$wpdb->postmeta as postmeta_season
        ON	postmeta_season.post_id = postmeta_episode.post_id AND
        	postmeta_season.meta_key = 'temporada'
        LEFT JOIN $wpdb->posts as posts
        ON	posts.ID = postmeta_episode.post_id AND
        	posts.post_status = 'publish' $by_vidsrc_sql
        $not_indb_sql_join
        WHERE
        	postmeta_episode.meta_key = 'episodio' AND
            posts.ID IS NOT NULL
            $not_indb_sql_where
        $limit
        ";
    }
                 
    if($count){
        $sql = "
        SELECT  
            COUNT(*)
        FROM 
            $wpdb->posts as posts
        WHERE
            posts.post_type = 'episodes' AND
            posts.post_status = 'publish' $by_vidsrc_sql
        "; 
    }
    
    
    if($count){
        return $wpdb->get_var($sql);
    }else{
        $eps_rows = $wpdb->get_results($sql);	
        
        $eps_arr = [];
        
        foreach($eps_rows as $ep_row){
            $ep_key = $ep_row->tmdb_id."_".$ep_row->season."_".$ep_row->episode;
            $eps_arr[$ep_key] = $ep_row->ID;
        }
        
        
        return $eps_arr;
    }
}


function get_all_tvs($by_vidsrc = 0){
    global $wpdb;
    
    
    $post_id_val_str = "";
    if($by_vidsrc)
        $post_id_val_str = ",\n posts.ID \n";
        
    $sql = "
        SELECT  
        	postmeta_tmdb.meta_value as tmdb_id
        	$post_id_val_str
        FROM
            $wpdb->posts as posts
        LEFT JOIN $wpdb->postmeta as postmeta_tmdb
        ON  postmeta_tmdb.meta_key = 'id' AND
            postmeta_tmdb.post_id = posts.ID
        WHERE   posts.post_type = 'tvshows' AND 
                posts.post_status = 'publish'
        ";
                 
    if($by_vidsrc)
        $sql .= " AND \n posts.post_author = 333";   
    
    $tvs_rows = $wpdb->get_results($sql);	
    
    $tvs_arr = [];
    
    foreach($tvs_rows as $tv_row){
        $tvs_arr[$tv_row->tmdb_id] = "";
        if($by_vidsrc)
            $tvs_arr[$tv_row->tmdb_id] = $tv_row->ID;
    }
    
    
    return $tvs_arr;
}


function vidsrc_add_term(){
    $term_row = $wpdb->get_row("
        SELECT terms.* FROM $wpdb->terms as terms
        LEFT JOIN $wpdb->term_taxonomy as term_tax
        ON	term_tax.term_id = terms.term_id
        WHERE
        	term_tax.taxonomy = 'dtquality' AND
            terms.name = '".$quality."'");	

    

    if($term_row){
    	$quality_cat_id[] = $term_row->term_id;
    }else{
        $quality_cat_term = wp_insert_term($quality, 'dtquality');
        if($quality_cat_term){
        	$quality_cat_id[] = $quality_cat_term['term_id'];
        }
        
    }
}

function vidsrc_new_post_iframes($post_id , $iframe_url){
    global $wpdb;
    
    
    $fields_res = $wpdb->get_results("
    SELECT
        post_excerpt ,
        post_name
    FROM $wpdb->posts
    WHERE
        post_excerpt IN( 
            'name_player',
            'type_player',
            'quality_player',
            'embed_player',
            'player'
        )
    ");
    
    $field_names = [
        'name_player',
        'type_player',
        'quality_player',
        'embed_player',
        'player'
    ];
    
    $fields = [];
    
    foreach($fields_res as $field){
        $fields[$field->post_excerpt] = $field->post_name;
    }
    
    
    foreach($field_names as $field_name){
        if(!isset($fields[$field_name])){
            echo "custom fields not set</br>\n";
            return 0;
        }
    }
    
    
    
    
    $player_meta = $wpdb->get_results("
    SELECT 
    	* 
    FROM $wpdb->postmeta as wp_postmeta
    WHERE 
    	wp_postmeta.post_id = '".$post_id."' AND
        (wp_postmeta.meta_key LIKE 'player_%' OR 
        wp_postmeta.meta_key LIKE '_player_%')");
    
    $current_iframe_data = [];
    foreach($player_meta as $meta){
        preg_match("/player_([0-9]+)_/" , $meta->meta_key , $match);
        if($match){
            $player_number = $match[1];
            if(!is_array($current_iframe_data[$player_number])){
                $current_iframe_data[$player_number] = [];
            }
            
            $current_iframe_data[$player_number][$meta->meta_key] = $meta->meta_value;
        }
    }
    
    $vidsrc_indb = 0;
    foreach($current_iframe_data as $player){
        foreach($player as $meta_value){
            preg_match("/vidsrc.me/i" , $meta_value , $match);
            if($match){
                $vidsrc_indb = 1;
                break;
            }
        }
    }
    
    if($vidsrc_indb){
        $player_data = [];
        $player_data['player'] = count($current_iframe_data);
        $player_data['_player'] = $fields['player'];
        $current_iframe_data[] = $player_data;
        
        return $current_iframe_data;
    }else{
        $new_iframe_data = [];
        
        foreach($current_iframe_data as $key => $data){
            $new_key = $key+1;
            $new_iframe_meta_data = [];
            
            foreach($current_iframe_data[$key] as $meta_key => $iframe_meta_data_row){
                $new_meta_key = str_replace("_".$key."_" , "_".$new_key."_" , $meta_key);
                $new_iframe_meta_data[$new_meta_key] = $iframe_meta_data_row;
            }
            
            
            $new_iframe_data[$new_key] = $new_iframe_meta_data; 
        }
        
        
        $new_iframe_data[0] = [];
        $new_iframe_data[0]["player_0_embed_player"] = $iframe_url;
        $new_iframe_data[0]["_player_0_embed_player"] = $fields['embed_player'];
        $new_iframe_data[0]["player_0_name_player"] = "VidSrc";
        $new_iframe_data[0]["_player_0_name_player"] = $fields['name_player'];
        $new_iframe_data[0]["player_0_type_player"] = "p_iframe";
        $new_iframe_data[0]["_player_0_type_player"] = $fields['type_player'];
        $new_iframe_data[0]["player_0_quality_player"] = "VidSrc";
        $new_iframe_data[0]["_player_0_quality_player"] = $fields['quality_player'];
        
        
        ksort($new_iframe_data);
        
        $player_data = [];
        $player_data['player'] = count($new_iframe_data);
        $player_data['_player'] = $fields['player'];
        
        $new_iframe_data[] = $player_data;
        
        return $new_iframe_data;
    }
    
}


function vidsrc_post_movie($vidsrc_data){

global $movies_not_added;

$imdb_id = $vidsrc_data->imdb_id;



if(empty($imdb_id)){	return ;}


global $wpdb;

$db_post = $wpdb->get_row("
    SELECT  posts.* ,
            postmeta_imdb.meta_value as imdb_id
    FROM $wpdb->posts as posts
    LEFT JOIN $wpdb->postmeta as postmeta_imdb
    ON  postmeta_imdb.post_id = posts.ID AND
        postmeta_imdb.meta_key = 'Checkbx2'
    WHERE
        posts.post_status = 'publish' AND
        postmeta_imdb.meta_value = '$imdb_id'");	

    $iframe_url = 'https://v2.vidsrc.me/embed/'.$imdb_id.'/'.getPlayerColor();



if(!empty($db_post)){
    
    
    
    
    $new_iframe_data = vidsrc_new_post_iframes($db_post->ID , $iframe_url);
    
    if(!$new_iframe_data)
        return 0;
    
    
    
    
    foreach($new_iframe_data as $iframe_data){
        foreach($iframe_data as $meta_key => $meta_value){
            if(!update_post_meta($db_post->ID, $meta_key, $meta_value)){
        	    add_post_meta($db_post->ID, $meta_key, $meta_value, true);
        	}
        }
    }
    
    
	
	vidsrc_insert_indb_row($db_post->ID , $vidsrc_data);
	return 'update';
	
}else{
    
    $vidsrc_mov_curl_data = curl_get_data('https://v2.vidsrc.me/api/m/'.$imdb_id);
    
    if($vidsrc_mov_curl_data == "not in db"){
        unset($movies_not_added[$imdb_id]);
        return 0;
    }
    
    $vidsrc_mov_data = json_decode($vidsrc_mov_curl_data);
    
    if(@$_GET['test'] == 1){
        exit("no add");
    }

    
    
    if(is_object($vidsrc_mov_data)){
        
        
        $movie_data = new stdClass();
        
        $movie_data->title = $vidsrc_mov_data->general->title;
        
        
        
        
        if(!empty($vidsrc_mov_data->tmdb->overview)){
            $movie_data->plot = $vidsrc_mov_data->tmdb->overview;
        }else{
            $movie_data->plot = $vidsrc_mov_data->general->plot;
        }
        
        
        
        $movie_data->poster = $vidsrc_mov_data->general->image;
        
        if(!empty($vidsrc_mov_data->tmdb->backdrop_path)){
            $movie_data->backdrop = 'https://image.tmdb.org/t/p/w780'.$vidsrc_mov_data->tmdb->backdrop_path;
        }
        
        if($vidsrc_mov_data->tmdb->runtime != 0){
            $movie_data->runtime = $vidsrc_mov_data->tmdb->runtime;
        }
        
        
        $movie_data->terms = [];
        
        
        // genres terms
        $movie_data->terms["category"] = [];
        if(is_array($vidsrc_mov_data->tmdb->genres)){
            foreach($vidsrc_mov_data->tmdb->genres as $genre){
                $movie_data->terms["category"][] = $genre->name;
            }
        }elseif(count($vidsrc_mov_data->general->genres)){
            $movie_data->terms["category"] = $vidsrc_mov_data->general->genres;
        }
        
        
        // year terms
        if(!empty($vidsrc_mov_data->general->year)){
            $movie_data->terms["release-year"] = $vidsrc_mov_data->general->year;
        }
        
        
        // directors terms
        $movie_data->terms["director"] = [];
        if(is_array($vidsrc_mov_data->tmdb->credits->crew)){
            foreach($vidsrc_mov_data->tmdb->credits->crew as $person){
                if($person->department == "Directing" || $person->job == "Director"){
                    $movie_data->terms["director"][] = $person->name;
                }
            }
        }
        if( is_array($vidsrc_mov_data->general->people->director) &&
            !count($movie_data->terms["director"])){
            foreach($vidsrc_mov_data->general->people->director as $person){
                $movie_data->terms["director"][] = $person;
            }
        }
        
        
        
        // cast terms
        $movie_data->terms["stars"] = [];
        if(is_array($vidsrc_mov_data->tmdb->credits->cast)){
            $count = 0;
            foreach($vidsrc_mov_data->tmdb->credits->cast as $person){
                $movie_data->terms["stars"][] = $person->name;
                $count++;
                if($count > 9)
                    break;
            }
        }
        if(is_array($vidsrc_mov_data->general->people->cast) &&
            !count($movie_data->terms["stars"])){
            $count = 0;
            foreach($vidsrc_mov_data->general->people->cast as $person){
                $movie_data->terms["stars"][] = $person;
                $count++;
                if($count > 9)
                    break;
            }
        }
        
        
        
        $movie_data->meta = [];
        
        // imdb 
        $movie_data->meta['Checkbx2'] = $imdb_id;
        
        if(is_numeric($vidsrc_mov_data->general->imdb_rating))
            $movie_data->meta['imdbRating'] = $vidsrc_mov_data->general->imdb_rating;
        
        $movie_data->meta['poster_url'] = $vidsrc_mov_data->general->image;
        
        // tmdb
        
        if(!empty($vidsrc_mov_data->tmdb->backdrop_path)){
            $movie_data->meta['fondo_player'] = "https://image.tmdb.org/t/p/w780".$vidsrc_mov_data->tmdb->backdrop_path;
        }
        
        if(isset($vidsrc_mov_data->tmdb->original_title)){
            $movie_data->meta['Title'] = $vidsrc_mov_data->tmdb->original_title;
        }
        
        if(!empty($vidsrc_mov_data->tmdb->release_date)){
            $movie_data->meta['release_date'] = $vidsrc_mov_data->tmdb->release_date;
        }
        
        if(!empty($vidsrc_mov_data->tmdb->vote_average)){
            $movie_data->meta['vote_average'] = $vidsrc_mov_data->tmdb->vote_average;
        }
        
        if(!empty($vidsrc_mov_data->tmdb->vote_count)){
            $movie_data->meta['vote_count'] = $vidsrc_mov_data->tmdb->vote_count;
        }
        
        if(!empty($vidsrc_mov_data->tmdb->tagline)){
            $movie_data->meta['tagline'] = $vidsrc_mov_data->tmdb->tagline;
        }
        
        if(!empty($vidsrc_mov_data->tmdb->runtime)){
            $movie_data->meta['Runtime'] = $vidsrc_mov_data->tmdb->runtime;
        }
        
        
        
        $post_insert_data = array(
          'post_title'    => $movie_data->title,
          'post_content'  => $movie_data->plot,
          'post_status'   => 'publish',
          'post_type'   => 'post',
          'post_author'   => "333",
      
    	);
    
        $new_post_id = wp_insert_post( $post_insert_data );
        
        if(is_numeric($new_post_id)){
            foreach($movie_data->terms as $taxonomy => $terms){
                setTerms($new_post_id , $terms , $taxonomy);
            }
            foreach($movie_data->meta as $meta_key => $meta_value){
                add_post_meta($new_post_id,$meta_key,$meta_value, true);
            }
            
            // player meta
            
            $new_iframe_data = vidsrc_new_post_iframes($new_post_id , $iframe_url);
            
            if(!$new_iframe_data)
                return 0;
            
            foreach($new_iframe_data as $iframe_data){
                foreach($iframe_data as $meta_key => $meta_value){
                    if(!update_post_meta($new_post_id, $meta_key, $meta_value)){
                	    add_post_meta($new_post_id, $meta_key, $meta_value, true);
                	}
                }
            }
        }else{
            exit("failed post insert movie");
        }
        
        vidsrc_insert_indb_row($new_post_id , $vidsrc_data);
        
        
        return "insert";
     
        
        
    }
    return 0 ;
    

}

}


function vidsrc_update_episode($vidsrc_data){
    
    
    
    
    global $wpdb;
    
    $db_post = $wpdb->get_row("
        SELECT  posts.* ,
    		postmeta_tmdb.meta_value as tmdb_id ,
    		postmeta_episode.meta_value as season,
            postmeta_season.meta_value as episode
        FROM $wpdb->posts as posts
        LEFT JOIN $wpdb->postmeta as postmeta_tmdb
        ON  postmeta_tmdb.post_id = posts.ID AND
            postmeta_tmdb.meta_key = 'ids'
        LEFT JOIN $wpdb->postmeta as postmeta_season
        ON  postmeta_season.post_id = posts.ID AND
            postmeta_season.meta_key = 'temporada'
        LEFT JOIN $wpdb->postmeta as postmeta_episode
        ON  postmeta_episode.post_id = posts.ID AND
            postmeta_episode.meta_key = 'episodio'
        WHERE
            posts.post_type = 'episodes' AND
            postmeta_tmdb.meta_value = '".$vidsrc_data->tmdb."' AND
            postmeta_season.meta_value = '".$vidsrc_data->season."' AND
            postmeta_episode.meta_value = '".$vidsrc_data->episode."'");
    
    
    $iframe_url = 'https://v2.vidsrc.me/embed/'.$vidsrc_data->imdb_id.'/'.$vidsrc_data->season."-".$vidsrc_data->episode."/".getPlayerColor();
        
    if(!empty($db_post)){
        
        $new_iframe_data = vidsrc_new_post_iframes($db_post->ID , $iframe_url);
        
        if(!$new_iframe_data)
            return 0;
        
        
        
        
        foreach($new_iframe_data as $iframe_data){
            foreach($iframe_data as $meta_key => $meta_value){
                if(!update_post_meta($db_post->ID, $meta_key, $meta_value)){
            	    add_post_meta($db_post->ID, $meta_key, $meta_value, true);
            	}
            }
        }
        
        vidsrc_insert_indb_row($db_post->ID , $vidsrc_data);
        
        return 1;
    }
    
}





function vidsrc_post_episodes($vidsrc_data){
    
    global $script_start;
    global $script_limit;
    
    global $ep_add_limit;
    global $ep_add_count;
    global $eps_not_added_gl;
    
    global $wpdb;
    
    if(!is_numeric($vidsrc_data->tmdb)){
        exit();
    }
    
    $vidsrc_tv_data_raw = curl_get_data('https://v2.vidsrc.me/api/t/'.$vidsrc_data->tmdb);
    
    if($vidsrc_tv_data_raw == "not in db"){
        $ep_key = $vidsrc_data->tmdb."_".$vidsrc_data->season."x".$vidsrc_data->episode;
        unset($eps_not_added_gl[$ep_key]);
        return 0;
    }
    
    $vidsrc_tv_data = json_decode($vidsrc_tv_data_raw);
    
    $vidsrc_data->imdb = $vidsrc_tv_data->general->imdb;
    
    if(@!is_object($vidsrc_tv_data->tmdb))
        exit();
    
    
    
    $db_post_tv = $wpdb->get_row("
        SELECT  posts.* ,
    		postmeta_tmdb.meta_value as tmdb_id
        FROM $wpdb->posts as posts
        LEFT JOIN $wpdb->postmeta as postmeta_tmdb
        ON  postmeta_tmdb.post_id = posts.ID AND
            postmeta_tmdb.meta_key = 'id'
        WHERE
            posts.post_type = 'tvshows' AND
            posts.post_status = 'publish' AND
            postmeta_tmdb.meta_value = '".$vidsrc_data->tmdb."' ");
    
    
    if(empty($db_post_tv)){
        if(@$_GET['test'] == 1){
            exit("no add");
        }
        $db_post_tv = vidsrc_post_tv($vidsrc_tv_data);
        if(!$db_post_tv){
            file_put_contents("vidsrc_error.txt",$vidsrc_data->tmdb." can't add to db");
            exit();
        }
    }
    
    
    $missing_episodes = [];
    $not_added_eps = get_episodes_not_added($vidsrc_data->tmdb);
    foreach($not_added_eps as $ep){
        if(!is_array($missing_episodes[$ep->season])){
            $missing_episodes[$ep->season] = [];
        }
        
        $missing_episodes[$ep->season][] = $ep->episode;
    }
    unset($not_added_eps);
    
    
    $tmdb_seasons_data = [];
    
    
    $add_limit_break = 0;
    
    
    foreach($missing_episodes as $mis_s => $mis_e){
        if(@!is_object($tmdb_seasons_data[$mis_s])){
            
            $tmdb_seasons_data[$mis_s] = json_decode(curl_get_data('https://v2.vidsrc.me/api/t/'.$vidsrc_data->tmdb."/".$mis_s));
            
            
            
            if(@!is_array($tmdb_seasons_data[$mis_s]->episodes)){
                exit();
            }
        }
        
        foreach($tmdb_seasons_data[$mis_s]->episodes as $vidsrc_ep_data){
            
            if(in_array($vidsrc_ep_data->episode_number,$missing_episodes[$vidsrc_ep_data->season_number])){
                
                $db_post_ep = $wpdb->get_row("
                SELECT  posts.* ,
            		postmeta_tmdb.meta_value as tmdb_id , 
            		postmeta_season.meta_value as season ,
            		postmeta_episode.meta_value as episode
                FROM $wpdb->posts as posts
                LEFT JOIN $wpdb->postmeta as postmeta_tmdb
                ON  postmeta_tmdb.post_id = posts.ID AND
                    postmeta_tmdb.meta_key = 'ids'
                LEFT JOIN $wpdb->postmeta as postmeta_season
                ON  postmeta_season.post_id = posts.ID AND
                    postmeta_season.meta_key = 'temporada'
                 LEFT JOIN $wpdb->postmeta as postmeta_episode
                ON  postmeta_episode.post_id = posts.ID AND
                    postmeta_episode.meta_key = 'episodio'
                WHERE
                    posts.post_type = 'episodes' AND
                    posts.post_status = 'publish' AND
                    postmeta_tmdb.meta_value = '".$vidsrc_tv_data->tmdb->id."' AND
                    postmeta_season.meta_value = '".$vidsrc_ep_data->season_number."' AND
                    postmeta_episode.meta_value = '".$vidsrc_ep_data->episode_number."'
                    ");
                
                
                
                $ep_key = $vidsrc_tv_data->tmdb->id."_".$vidsrc_ep_data->season_number."_".$vidsrc_ep_data->episode_number;
                
                if(empty($db_post_ep)){
                    if(vidsrc_post_episode($vidsrc_tv_data , $vidsrc_ep_data)){
                        
                        $eps_not_added_gl[$ep_key] = "";
                        
                        $ep_add_count++;
                        if($ep_add_count >= $ep_add_limit){
                            vidsrc_update_tv_ep_fields($db_post_tv->ID , $vidsrc_data->tmdb);
                            exit();
                        }
                    }else{
                        exit();
                    }
                }else{
                    $insert_indb_data = new stdClass();
                    $insert_indb_data->tmdb = $db_post_ep->tmdb_id;
                    $insert_indb_data->season = $db_post_ep->season;
                    $insert_indb_data->episode = $db_post_ep->episode;
                    
                    vidsrc_insert_indb_row($db_post_ep->ID , $insert_indb_data);
                    $eps_not_added_gl[$ep_key] = "";
                    
                }
            }
            
            if(microtime(true)-$script_start > $script_limit)
                exit();
        }
        usleep(500000);
    }
    
    vidsrc_update_tv_ep_fields($db_post_tv->ID , $vidsrc_data->tmdb);
    
}


function vidsrc_update_tv_ep_fields($tv_post_id , $tmdb){
    global $wpdb;
    
    
    $fields_res = $wpdb->get_results("
    SELECT
        post_excerpt ,
        post_name
    FROM $wpdb->posts
    WHERE
        post_excerpt IN( 
            'temporadas',
            'episodios',
            'slug',
            'titlee'
        )
    ");
    
    $field_names = [
        'temporadas',
        'episodios',
        'slug',
        'titlee'
    ];
    
    $fields = [];
    
    foreach($fields_res as $field){
        $fields[$field->post_excerpt] = $field->post_name;
    }
    
    
    foreach($field_names as $field_name){
        if(!isset($fields[$field_name])){
            echo "custom fields not set</br>\n";
            return 0;
        }
    }
    
    
    
    $episodes = $wpdb->get_results("
        SELECT  
        	posts.post_title as episode_name ,
            posts.post_name as episode_slug ,
    		postmeta_tmdb.meta_value as tmdb_id , 
    		postmeta_season.meta_value as season ,
    		postmeta_episode.meta_value as episode
        FROM $wpdb->posts as posts
        LEFT JOIN $wpdb->postmeta as postmeta_tmdb
        ON  postmeta_tmdb.post_id = posts.ID AND
            postmeta_tmdb.meta_key = 'ids'
        LEFT JOIN $wpdb->postmeta as postmeta_season
        ON  postmeta_season.post_id = posts.ID AND
            postmeta_season.meta_key = 'temporada'
         LEFT JOIN $wpdb->postmeta as postmeta_episode
        ON  postmeta_episode.post_id = posts.ID AND
            postmeta_episode.meta_key = 'episodio'
        WHERE
            posts.post_type = 'episodes' AND
            posts.post_status = 'publish' AND
            postmeta_tmdb.meta_value = '".$tmdb."'
    ");
    
    $tmp_eps = [];
    
    foreach($episodes as $ep){
        if(!is_array($tmp_eps[$ep->season])){
            $tmp_eps[$ep->season] = [];
        }
        $tmp_eps[$ep->season][$ep->episode] = new stdClass();
        $tmp_eps[$ep->season][$ep->episode]->name = $ep->episode_name;
        $tmp_eps[$ep->season][$ep->episode]->slug = $ep->episode_slug;
    }
    
    ksort($tmp_eps);
    foreach($tmp_eps as $key => $val){
        ksort($tmp_eps[$key]);        
    }
    
    
    $new_fields_data = [];
    
    if(empty($tmp_eps)){
        return 0;
    }
    
    $tmp_arr = [];
    $tmp_arr['temporadas'] = max(array_keys($tmp_eps));
    $tmp_arr['_temporadas'] = $fields['temporadas'];
    
    $new_fields_data[] = $tmp_arr;
    
    foreach($tmp_eps as $season => $eps){
        
        $tmp_arr = [];
        $tmp_arr['temporadas_'.($season-1).'_episodios'] = max(array_keys($eps));
        $tmp_arr['_temporadas_'.($season-1).'_episodios'] = $fields['episodios'];
        $new_fields_data[] = $tmp_arr;
        
        foreach($eps as $episode => $ep){
            $tmp_arr = [];
            $tmp_arr['temporadas_'.($season-1).'_episodios_'.($episode-1).'_slug'] = $ep->slug;
            $tmp_arr['_temporadas_'.($season-1).'_episodios_'.($episode-1).'_slug'] = $fields['slug'];
            $tmp_arr['temporadas_'.($season-1).'_episodios_'.($episode-1).'_titlee'] = $ep->name;
            $tmp_arr['_temporadas_'.($season-1).'_episodios_'.($episode-1).'_titlee'] = $fields['titlee'];
            $new_fields_data[] = $tmp_arr;
        }
        
    }
    
    
    foreach($new_fields_data as $field_data){
        foreach($field_data as $meta_key => $meta_value){
            if(!update_post_meta($tv_post_id, $meta_key, $meta_value)){
        	    add_post_meta($tv_post_id, $meta_key, $meta_value, true);
        	}
        }
    }
    
}


function vidsrc_post_tv($vidsrc_tv_data){
    
    
    $tv_data = new stdClass();
    
    $tv_data->title = $vidsrc_tv_data->general->title;
    
    
    
    
    if(!empty($vidsrc_tv_data->tmdb->overview)){
        $tv_data->plot = $vidsrc_tv_data->tmdb->overview;
    }else{
        $tv_data->plot = $vidsrc_tv_data->general->plot;
    }
    
    
    
    
    $tv_data->terms = [];
    
    // genres terms
    $tv_data->terms["category"] = [];
    if(is_array($vidsrc_tv_data->tmdb->genres)){
        foreach($vidsrc_tv_data->tmdb->genres as $genre){
            $tv_data->terms["category"][] = $genre->name;
        }
    }elseif(count($vidsrc_tv_data->general->genres)){
        $tv_data->terms["category"] = $vidsrc_tv_data->general->genres;
    }
    
    
    // year terms
    if(!empty($vidsrc_tv_data->general->year)){
        $tv_data->terms["release-year"] = $vidsrc_tv_data->general->year;
    }
    
    
    // directors terms
    $tv_data->terms["director"] = [];
    if(is_array($vidsrc_tv_data->tmdb->created_by)){
        foreach($vidsrc_tv_data->tmdb->created_by as $person){
                $tv_data->terms["director"][] = $person->name;
        }
    }
    
    
    
    // cast terms
    $tv_data->terms["stars"] = [];
    if(is_array($vidsrc_tv_data->tmdb->credits->cast)){
        $count = 0;
        foreach($vidsrc_tv_data->tmdb->credits->cast as $person){
            $tv_data->terms["stars"][] = $person->name;
            $count++;
            if($count > 9)
                break;
        }
    }
    if(is_array($vidsrc_tv_data->general->people->cast) &&
        !count($tv_data->terms["stars"])){
        $count = 0;
        foreach($vidsrc_tv_data->general->people->cast as $person){
            $tv_data->terms["stars"][] = $person;
            $count++;
            if($count > 9)
                break;
        }
    }
    
    
    
    $tv_data->meta = [];
    
    // imdb 
    
    
    $tv_data->meta['poster_url'] = $vidsrc_tv_data->general->image;
    
    // tmdb
    $tv_data->meta['id'] = $vidsrc_tv_data->tmdb->id;
    
    if(!empty($vidsrc_tv_data->tmdb->backdrop_path)){
        $tv_data->meta['fondo_player'] = "https://image.tmdb.org/t/p/w780".$vidsrc_tv_data->tmdb->backdrop_path;
    }
    
    if(isset($vidsrc_tv_data->tmdb->original_title)){
        $tv_data->meta['original_name'] = $vidsrc_tv_data->tmdb->original_title;
    }
    
    if(!empty($vidsrc_tv_data->tmdb->status)){
        $tv_data->meta['status'] = $vidsrc_tv_data->tmdb->status;
    }
    
    if(!empty($vidsrc_tv_data->tmdb->vote_average)){
        $tv_data->meta['serie_vote_average'] = $vidsrc_tv_data->tmdb->vote_average;
    }
    
    if(!empty($vidsrc_tv_data->tmdb->vote_count)){
        $tv_data->meta['serie_vote_count'] = $vidsrc_tv_data->tmdb->vote_count;
    }
    
    
    if(!empty($vidsrc_tv_data->tmdb->episode_run_time)){
        $tv_data->meta['episode_run_time'] = implode(",",$vidsrc_tv_data->tmdb->episode_run_time);
    }
    
    if(!empty($vidsrc_tv_data->tmdb->first_air_date)){
        $tv_data->meta['first_air_date'] = $vidsrc_tv_data->tmdb->first_air_date;
    }
    
    
    if(!empty($vidsrc_tv_data->tmdb->last_air_date)){
        $tv_data->meta['last_air_date'] = $vidsrc_tv_data->tmdb->last_air_date;
    }
    
    
    
    $post_insert_data = array(
      'post_title'    => $tv_data->title,
      'post_content'  => $tv_data->plot,
      'post_status'   => 'publish',
      'post_type'   => 'tvshows',
      'post_author'   => "333",
  
	);
    
    $new_post_id = wp_insert_post( $post_insert_data );
    
    
    if(is_numeric($new_post_id)){
        foreach($tv_data->terms as $taxonomy => $terms){
            setTerms($new_post_id , $terms , $taxonomy);
        }
        foreach($tv_data->meta as $meta_key => $meta_value){
            add_post_meta($new_post_id,$meta_key,$meta_value, true);
        }
        
        
    }else{
        exit("failed post insert tv show");
    }
    
    return get_post($new_post_id);
}



function vidsrc_post_episode($vidsrc_tv_data , $vidsrc_ep_data){
    
    $ep_data = new stdClass();
    
    $ep_data->title = $vidsrc_tv_data->general->title." Season ".$vidsrc_ep_data->season_number." Episode ".$vidsrc_ep_data->episode_number;
    
    
    $ep_data->plot = $vidsrc_ep_data->overview;
    
    
    $ep_data->meta = [];
    
    // tmdb
    $ep_data->meta['ids'] = $vidsrc_tv_data->tmdb->id;
    
    $ep_data->meta['temporada'] = $vidsrc_ep_data->season_number;
    
    $ep_data->meta['episodio'] = $vidsrc_ep_data->episode_number;
    
    $ep_data->meta['serie'] = $vidsrc_tv_data->general->title;
    
    
    if(!empty($vidsrc_ep_data->name)){
        $ep_data->meta['name'] = $vidsrc_ep_data->name;
    }
    
    if(!empty($vidsrc_ep_data->air_date)){
        $ep_data->meta['air_date'] = $vidsrc_ep_data->air_date;
    }
    
    $ep_data->meta['poster_serie'] = $vidsrc_tv_data->general->image;
    
    if(!empty($vidsrc_ep_data->still_path)){
        $ep_data->meta['fondo_player'] = $vidsrc_ep_data->still_path;
    }
    
    
    
    $post_insert_data = array(
      'post_title'    => $ep_data->title,
      'post_content'  => $ep_data->plot,
      'post_status'   => 'publish',
      'post_type'   => 'episodes',
      'post_author'   => "333",
  
	);
	
	
    $new_post_id = wp_insert_post( $post_insert_data );
    
	
    if(is_numeric($new_post_id)){
        foreach($ep_data->meta as $meta_key => $meta_value){
            add_post_meta($new_post_id,$meta_key,$meta_value, true);
        }
        
        
        // player meta

        $iframe_url = 'https://vidsrc.me/embed/'.$vidsrc_tv_data->general->imdb."/".$vidsrc_ep_data->season_number."-".$vidsrc_ep_data->episode_number."/".getPlayerColor();
        $new_iframe_data = vidsrc_new_post_iframes($new_post_id , $iframe_url);
        
        if(!$new_iframe_data)
            return 0;
        
        foreach($new_iframe_data as $iframe_data){
            foreach($iframe_data as $meta_key => $meta_value){
                if(!update_post_meta($new_post_id, $meta_key, $meta_value)){
            	    add_post_meta($new_post_id, $meta_key, $meta_value, true);
            	}
            }
        }
        
        
    }else{
        exit("failed post insert episode");
    }
    
    $vidsrc_data = new stdClass();
    $vidsrc_data->tmdb = $vidsrc_tv_data->tmdb->id;
    $vidsrc_data->season = $vidsrc_ep_data->season_number;
    $vidsrc_data->episode = $vidsrc_ep_data->episode_number;
    
    vidsrc_insert_indb_row($new_post_id , $vidsrc_data);
    
    return 1;
    
}

function clear_dbmv_cache($tmdb){
    
    $files = scandir(DBMOVIES_CACHE_DIR);
    
    $matches = [];
    foreach($files as $file){
        preg_match("/".$tmdb."/i" , $file , $match);
        if($match){
            unlink(DBMOVIES_CACHE_DIR . $file);
        }
    }
}


function pingVidsrc(){
    $url = "https://v2.vidsrc.me/ping.php";
    $postdata = [
        "dom"   => get_site_url()
        ];
    curl_get_data($url , http_build_query($postdata));
}


function setTerms($post_id , $terms , $taxonomy){
    if(!is_array($terms)){
        if(strlen($terms)){
            $terms = [$terms];
        }else{
            exit();
        }
    }
    
    $term_ids = [];
    
    foreach($terms as $term){
    	$term_row = get_term_by( 'name', $term, $taxonomy);
    
        if($term_row){
        	$term_ids[] = $term_row->term_id;
        }else{
            $new_term = wp_insert_term($term , $taxonomy);
            
            if($new_term){
            	$term_ids[] = $new_term['term_id'];
            }
        }
    }
    
	if(wp_set_post_terms($post_id,$term_ids,$taxonomy,false)){
	    return 1;
	}else{
	    return 0;
	}
}



function curl_get_data($vidsrc_url,$vidsrc_post_data='') {
	
	$vidsrc_ch = curl_init();
	$vidsrc_timeout = 15;
	curl_setopt($vidsrc_ch,CURLOPT_URL,$vidsrc_url);
	curl_setopt($vidsrc_ch,CURLOPT_RETURNTRANSFER, true);
	curl_setopt($vidsrc_ch,CURLOPT_USERAGENT,"Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1" );
	curl_setopt($vidsrc_ch,CURLOPT_REFERER,'http://www.imdb.com');
	curl_setopt($vidsrc_ch,CURLOPT_CONNECTTIMEOUT,$vidsrc_timeout);
	curl_setopt($vidsrc_ch, CURLOPT_FOLLOWLOCATION, TRUE);
	curl_setopt($vidsrc_ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($vidsrc_ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($vidsrc_ch,CURLOPT_COOKIEJAR,'cookies.txt');
	curl_setopt($vidsrc_ch,CURLOPT_COOKIEFILE,'cookies.txt');
	curl_setopt($vidsrc_ch, CURLOPT_SSL_VERIFYPEER, false);
	if(!empty($vidsrc_post_data))
	{
		 curl_setopt($vidsrc_ch,CURLOPT_POST, 1);
		 curl_setopt($vidsrc_ch, CURLOPT_POSTFIELDS, $vidsrc_post_data);
	}
	$vidsrc_data = curl_exec($vidsrc_ch);
	
	echo curl_error($vidsrc_ch);
	curl_close($vidsrc_ch);
	return $vidsrc_data;
  }
  
  
function vidsrc_get_list_movies_updated(){
    $vidsrc_a = array();
    global $wpdb;
    $vidsrc_total_episodes_row = $wpdb->get_row("
        SELECT 
        	COUNT(*) as count
        FROM $wpdb->postmeta as wp_postmeta
        LEFT JOIN $wpdb->posts as wp_posts
        ON	wp_posts.ID = wp_postmeta.post_id AND
        	wp_posts.post_type = 'episodes' AND
            wp_posts.post_status = 'publish'
        WHERE
        	wp_postmeta.meta_key LIKE '%embed_player%' AND
            wp_postmeta.meta_value like '%vidsrc.me%' AND 
            wp_posts.ID IS NOT NULL
    ");	
    $vidsrc_a['episodes'] = $vidsrc_total_episodes_row->count;
    $vidsrc_total_movies_row = $wpdb->get_row("
        SELECT 
        	COUNT(*) as count
        FROM $wpdb->postmeta as wp_postmeta
        LEFT JOIN $wpdb->posts as wp_posts
        ON	wp_posts.ID = wp_postmeta.post_id AND
        	wp_posts.post_type = 'post' AND
            wp_posts.post_status = 'publish'
        WHERE
        	wp_postmeta.meta_key LIKE '%embed_player%' AND
            wp_postmeta.meta_value like '%vidsrc.me%' AND 
            wp_posts.ID IS NOT NULL
    ");	
    $vidsrc_a['movies'] = $vidsrc_total_movies_row->count;
    
    return $vidsrc_a;
}

function vidsrc_get_list_movies_total(){
    $vidsrc_a = array();
    global $wpdb;
    $vidsrc_total_episodes_row = $wpdb->get_row("
        SELECT 
        	COUNT(*) as count
        FROM $wpdb->posts as wp_posts
        WHERE
        	wp_posts.post_type = 'episodes' AND
            wp_posts.post_status = 'publish'
    ");	
    $vidsrc_a['episodes'] = $vidsrc_total_episodes_row->count;
    $vidsrc_total_movies_row = $wpdb->get_row("
        SELECT 
        	COUNT(*) as count
        FROM $wpdb->posts as wp_posts
        WHERE
        	wp_posts.post_type = 'post' AND
            wp_posts.post_status = 'publish'
    ");	
    $vidsrc_a['movies'] = $vidsrc_total_movies_row->count;
    
    return $vidsrc_a;
}

function get_movies_not_added(){
    
    global $wpdb;
    global $movies_add_limit;
    global $vidsrc_mov_file;
    global $indb_mov_file;
    
    $vidsrc_mov = file($vidsrc_mov_file);
    $indb_mov = file($indb_mov_file);
    
    
    $mov_diff = array_diff($vidsrc_mov , $indb_mov);
    
    
    $not_added_mov = [];
    foreach($mov_diff as $mov){
        $mov = trim($mov);
        $mov_tmp = new stdClass();
        $mov_tmp->imdb_id = $mov;
        $not_added_mov[$mov] = $mov_tmp;
    }
    
    return $not_added_mov;
    
    exit();
}

function get_movies_not_updated(){
    global $wpdb;
    global $movies_add_limit;
    
    
    $not_updated_movs = $wpdb->get_results("
        SELECT  
        	postmeta_imdb.meta_value as imdb_id 
        FROM
        	$wpdb->posts as posts
        LEFT JOIN $wpdb->postmeta as postmeta_imdb
        ON  postmeta_imdb.meta_key = 'Checkbx2' AND
        	postmeta_imdb.post_id = posts.ID
        LEFT JOIN ".$wpdb->prefix."vidsrc as vidsrc
        ON  vidsrc.imdb = postmeta_imdb.meta_value
        WHERE   
                vidsrc.imdb IS NOT NULL AND
                postmeta_imdb.meta_value IS NOT NULL AND
        		posts.post_type = 'post' AND 
        		posts.post_status = 'publish' AND
                posts.ID NOT IN(
                    SELECT 
                        post_id 
                    FROM    $wpdb->postmeta
                    WHERE
                        wp_postmeta.meta_value LIKE '%vidsrc.me%'
                    GROUP by post_id
                )
        LIMIT ".$movies_add_limit);	
    
    return $not_updated_movs;
}

function get_episodes_not_updated(){
    global $wpdb;
    global $ep_add_limit;
    
    $not_updated_eps = $wpdb->get_results("
        SELECT  
            vidsrc.imdb as imdb_id ,
        	postmeta_tmdb.meta_value as tmdb ,
            postmeta_season.meta_value as season,
            postmeta_episode.meta_value as episode
        FROM
            $wpdb->posts as posts
        LEFT JOIN $wpdb->postmeta as postmeta_tmdb
        ON  postmeta_tmdb.meta_key = 'ids' AND
            postmeta_tmdb.post_id = posts.ID
        LEFT JOIN $wpdb->postmeta as postmeta_season
        ON  postmeta_season.meta_key = 'temporada' AND
            postmeta_season.post_id = posts.ID
        LEFT JOIN $wpdb->postmeta as postmeta_episode
        ON  postmeta_episode.meta_key = 'episodio' AND
            postmeta_episode.post_id = posts.ID
        LEFT JOIN ".$wpdb->prefix."vidsrc as vidsrc
        ON  vidsrc.tmdb = postmeta_tmdb.meta_value AND
            vidsrc.season = postmeta_season.meta_value AND
            vidsrc.episode = postmeta_episode.meta_value
        WHERE   
            vidsrc.tmdb IS NOT NULL AND
            posts.post_type = 'episodes' AND 
            posts.post_status = 'publish' AND
            posts.ID NOT IN(
                SELECT 
                post_id 
                FROM	wp_postmeta
                WHERE
                wp_postmeta.meta_value LIKE '%vidsrc.me%'
            )
        LIMIT ".$ep_add_limit);	
    
    return $not_updated_eps;
}

function get_episodes_not_added($tmdb = 0){
    
    global $wpdb;
    global $ep_add_limit;
    global $vidsrc_eps_file;
    global $indb_eps_file;
    
    $vidsrc_eps = file($vidsrc_eps_file);
    $indb_eps = file($indb_eps_file);
    
    
    $eps_diff = array_diff($vidsrc_eps , $indb_eps);
    
    $not_added_eps = [];
    foreach($eps_diff as $ep){
        preg_match("/^([0-9]+)_([0-9]+)x([0-9]+)$/" , $ep , $match_info);
        $ep_tmp = new stdClass();
        $ep_tmp->tmdb = $match_info[1];
        $ep_tmp->season = $match_info[2];
        $ep_tmp->episode = $match_info[3];
        $ep_key = $ep_tmp->tmdb."_".$ep_tmp->season."x".$ep_tmp->episode;
        
        if($tmdb){
            if($ep_tmp->tmdb == $tmdb)
                $not_added_eps[$ep_key] = $ep_tmp;
        }else{
            $not_added_eps[$ep_key] = $ep_tmp;
        }
    }
    
    return $not_added_eps;
    
    exit();
    
    if(@!is_numeric($ep_add_limit)){
        $ep_add_limit = 50;
    }
    
    if(@is_numeric($tmdb) && @$tmdb){
        $sql_append = "vidsrc.tmdb = ".$tmdb." AND";
        $sql_order_by = "
        ORDER by vidsrc.tmdb , vidsrc.season , vidsrc.episode ";
    }else{
        $sql_append = "vidsrc.season IS NOT NULL AND";
        $sql_order_by = "";
    }
    
    
    $not_added_eps = $wpdb->get_results("
        SELECT  
            vidsrc.imdb as imdb_id ,
        	vidsrc.tmdb ,
            vidsrc.season ,
            vidsrc.episode
        FROM
        	".$wpdb->prefix."vidsrc as vidsrc
        WHERE   
        	".$sql_append."
			vidsrc.post_id IS NULL".$sql_order_by."
        LIMIT ".$ep_add_limit);	
    
    return $not_added_eps;
}


if(@$_GET['vidsrc_stats']){
    list_movies_settings();
    exit();
}

function list_movies_settings(){
    //$vidsrc_movies_list_updated = vidsrc_get_list_movies_updated();
    $vidsrc_movies_list_total = vidsrc_get_list_movies_total();
    ?>
        	<div>
        	    <b>Total Movies:</b> <?php echo $vidsrc_movies_list_total['movies']; ?><br />
            	<b>Total Episodes:</b> <?php echo $vidsrc_movies_list_total['episodes']; ?><br />
            	<!--<b>Total Movies Updated:</b> <?php echo $vidsrc_movies_list_updated['movies']; ?><br />
            	<b>Total Episodes Updated:</b> <?php echo $vidsrc_movies_list_updated['episodes']; ?><br />-->
        	</div>
    <?php
}

function vidsrc_settings_page() {


if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] == true ) {
$vidsrc_cron_time = get_option('vidsrc_cron');


    if(get_option('vidsrc_active')){
        wp_clear_scheduled_hook('vidsrc_add_data_event');
    	wp_clear_scheduled_hook('vidsrc_add_data_indb_event');
        wp_clear_scheduled_hook('vidsrc_add_data_latest_event');
    	wp_schedule_event(time(), '1min', 'vidsrc_add_data_event');
        wp_schedule_event(time(), '15min', 'vidsrc_add_data_indb_event');
        wp_schedule_event(time(), '15min', 'vidsrc_add_data_latest_event');
    }else{
        wp_clear_scheduled_hook('vidsrc_add_data_event');
    	wp_clear_scheduled_hook('vidsrc_add_data_indb_event');
    	wp_clear_scheduled_hook('vidsrc_add_data_latest_event');
    }
    
    if($vidsrc_cron_time != 'off'){
        	wp_clear_scheduled_hook('vidsrc_movies_event');
        	wp_clear_scheduled_hook('vidsrc_tvshow_event');
            wp_clear_scheduled_hook('vidsrc_clean_duplicates_event');
            wp_clear_scheduled_hook('vidsrc_update_player_color_event');
        	wp_clear_scheduled_hook('vidsrc_clean_dead_titles_event');
        	wp_clear_scheduled_hook('vidsrc_ping_active_event');
        	
        	wp_schedule_event(time(), $vidsrc_cron_time.'min', 'vidsrc_movies_event');
        	wp_schedule_event(time(), $vidsrc_cron_time.'min', 'vidsrc_tvshow_event');
        	wp_schedule_event(time(), '10h', 'vidsrc_clean_duplicates_event');
            wp_schedule_event(time(), '30min', 'vidsrc_update_player_color_event');
            wp_schedule_event(time(), 'twicedaily', 'vidsrc_clean_dead_titles_event');
            wp_schedule_event(time(), 'twicedaily', 'vidsrc_ping_active_event');
    }else{
    	wp_clear_scheduled_hook('vidsrc_movies_event');
    	wp_clear_scheduled_hook('vidsrc_tvshow_event');
    	wp_clear_scheduled_hook('vidsrc_clean_duplicates_event');
        wp_clear_scheduled_hook('vidsrc_update_player_color_event');
    	wp_clear_scheduled_hook('vidsrc_clean_dead_titles_event');
    	wp_clear_scheduled_hook('vidsrc_ping_active_event');
    }
}
?>
<div class="wrap">
	<h2>VidSrc PsyPlay</h2>

	<form method="post" action="options.php">
    <?php settings_fields( 'vidsrc_settings' ); ?>
    <?php do_settings_sections( 'vidsrc_settings' ); ?>
    <table id="the_table" class="form-table">
		<tr style="width:420px" valign="top">
			<th scope="row"><?php _e('Activate','VidSrc');?> VidSrc</th>
			<td><input type="checkbox" name="vidsrc_active" <?php echo get_option('vidsrc_active')?'checked="checked"':''; ?>/></td>
        </tr>
		<tr style="width:420px" valign="top">
			<th scope="row"><?php _e('Cron time','VidSrc');?></th>
			<td>
			<select style="width:120px" name="vidsrc_cron">
				<option value="off" <?php echo(get_option('vidsrc_cron')=="off"?'selected="selected"':'')?>><?php _e('off','VidSrc');?></option>
				<option value="5" <?php echo(get_option('vidsrc_cron')=="5"?'selected="selected"':'')?>><?php _e('5 min','VidSrc');?></option>
				<option value="15" <?php echo(get_option('vidsrc_cron')=="15"?'selected="selected"':'')?>><?php _e('15 min','VidSrc');?></option>
				<option value="30" <?php echo(get_option('vidsrc_cron')=="30"?'selected="selected"':'')?>><?php _e('30 min','VidSrc');?></option>
				

			</select>
			</td>
			<td>How often do you want to have the server run to add new movies and episodes. The faster it is set the more pressure it has on the site. For Shared hosting every 15 minutes is recommended. For dedicated, Every 5 mins should be fine. 
			</td>
        </tr>
        <tr style="width:420px" valign="top">
			<th scope="row">Movies Add </th>
			<td><input type="number" min="1" max="50" name="vidsrc_movies_no" value="<?php echo get_option('vidsrc_movies_no'); ?>" /></td>
			<td>How many movies do you want to add on every cron run.  Default 10. Max 50.</td>
        </tr>
        <tr style="width:420px" valign="top">
			<th scope="row">Episodes Add </th>
			<td><input type="number" min="1" max="150" name="vidsrc_episodes_no" value="<?php echo get_option('vidsrc_episodes_no'); ?>" /></td>
			<td>How many episodes do you want to add on every cron run.  Default 50. Max 150.</td>
        </tr>
        
        
        <tr style="width:420px" valign="top">
			<th scope="row">Player color:</th>
            <?php 
            $player_color = get_option('vidsrc_player_color');
            if(@!strlen($player_color))
                $player_color = "auto";
            ?>
			<td>
			    <label for="color_radio_manual">
			        <input id="color_radio_manual" class="color_radio" type="radio" name="player_color_mode" <?php if(strlen($player_color) == 7){ ?>checked<?php } ?> value="manual">
                    <input id="player_color_mode_manual" type="color" <?php if(strlen($player_color) == 7){ ?>name="vidsrc_player_color" value="<?php echo $player_color; ?>"<?php } ?> />
                </label>
                </br>
                <label for="color_radio_auto">
                    <input id="color_radio_auto" class="color_radio" type="radio" name="player_color_mode" <?php if($player_color == "auto"){ ?>checked<?php } ?> value="auto"> auto
    			    <input id="player_color_mode_auto" type="hidden" <?php if($player_color == "auto"){ ?>name="vidsrc_player_color"<?php } ?> value="auto" />
			    </label>
			    <script>
			        document.getElementById("color_radio_manual").addEventListener("click", function() {
                        document.getElementById("player_color_mode_auto").removeAttribute("name");
                        document.getElementById("player_color_mode_manual").setAttribute("name" , "vidsrc_player_color");
                    });
                    document.getElementById("color_radio_auto").addEventListener("click", function() {
                        document.getElementById("player_color_mode_manual").removeAttribute("name");
                        document.getElementById("player_color_mode_auto").setAttribute("name" , "vidsrc_player_color");
                    });
                    
                    document.getElementById("player_color_mode_manual").addEventListener("change" , function() {
                        document.getElementById("player_color_mode_auto").removeAttribute("name");
                        document.getElementById("player_color_mode_manual").setAttribute("name" , "vidsrc_player_color");
                        document.getElementById("color_radio_auto").checked = false;
                        document.getElementById("color_radio_manual").checked = true;
                    });
			    </script>
			</td>
			<td>Select a color for the player. Changing this value may take few hours to affect all movies and episodes.</td>
        </tr>
        
        <tr style="width:420px" valign="top">
			<th scope="row">Get player stats:</th>
			<td id="get_stats_parent"><button id="get_stats" onclick="getStats()">Get stats</button></td>
        </tr>
        
        <tr id="list_results"> </tr>
        
        <script>
	        function getStats(){
                this.disabled = true;
                document.getElementById("get_stats_parent").innerHTML = "Loading...";
                document.getElementById("list_results").innerHTML = " ";
                
                var xhttp = new XMLHttpRequest();
                xhttp.onreadystatechange = function() {
                    if (this.readyState == 4 && this.status == 200) {
                        document.getElementById("list_results").innerHTML = this.responseText;
                        document.getElementById("get_stats_parent").innerHTML = '<button id="get_stats" onclick="getStats()">Get stats</button>';
                    }
                };
                xhttp.open("GET", document.location+"&vidsrc_stats=1&t=" + new Date().getTime(), true);
                xhttp.send();
            };
        </script>
		
    </table>
    <?php submit_button(); ?>
	</form>
	
</div>
<?php
}

	
function vidsrc_register_mysettings() {
	//register our settings
	register_setting( 'vidsrc_settings', 'vidsrc_active' );
	register_setting( 'vidsrc_settings', 'vidsrc_cron' );
	register_setting( 'vidsrc_settings', 'vidsrc_player_color' );
	register_setting( 'vidsrc_settings', 'vidsrc_movies_no' );
	register_setting( 'vidsrc_settings', 'vidsrc_episodes_no' );
	register_setting( 'vidsrc_settings', 'vidsrc_sync' );
	register_setting( 'vidsrc_settings', 'vidsrc_sync_indb' );
}

function vidsrc_settings_link($vidsrc_links ,$plugin_file) { 
    $vidsrc_settings_link = [];
    $vidsrc_settings_link= '<a href="admin.php?page=vidsrc_psyplay">Settings</a>';
    array_unshift($vidsrc_links, $vidsrc_settings_link);
    return $vidsrc_links; 
}

function vidsrc_install() {
	if ( !vidsrc_plugin_current_version() ) 
	{ 
		vidsrc_plugin_activation();
	}
	else
	{
	
	
	
	ini_set('max_execution_time', '300');
	
	global $wpdb;

    $table_name_vidsrc = $wpdb->prefix . "vidsrc";     
    $table_name_vidsrc_indb = $wpdb->prefix . "vidsrc_indb";
    
    $wpdb->query( "DROP TABLE IF EXISTS ".$table_name_vidsrc );
    
    $charset_collate = $wpdb->get_charset_collate() . ' engine = innoDB';
    
    
    $sql = "
    CREATE TABLE IF NOT EXISTS $table_name_vidsrc (
        `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `post_id` BIGINT(20) UNSIGNED NULL ,
        `imdb` VARCHAR(15) NOT NULL,
        `tmdb` INT NULL DEFAULT NULL,
        `season` INT NULL DEFAULT NULL,
        `episode` INT NULL DEFAULT NULL,
        `quality` VARCHAR(10) NOT NULL,
        `last_update` BIGINT NOT NULL,
        `unique_md5` VARCHAR(32) AS(
            MD5(
                CONCAT(
                    `imdb`,
                    IF(
                        `season` IS NOT NULL,
                        CONCAT('_', `season`, 'x', `episode`),
                        ''
                    )
                )
            )
        ) VIRTUAL ,
        `unique_md5_tmdb` VARCHAR(32) AS(
            md5(
                concat(`tmdb`,'_',`season`,'_',`episode`)
            )
        ) VIRTUAL ,
        UNIQUE INDEX (`unique_md5`) ,
        UNIQUE INDEX (`unique_md5_tmdb`) ,
        INDEX (`imdb`) ,
        INDEX (`tmdb`) ,
        INDEX (`imdb` , `season` , `episode`) ,
        INDEX (`tmdb` , `season` , `episode`) ,
        FOREIGN KEY (`post_id`) 
        REFERENCES `".$wpdb->posts."`(`ID`) 
        ON DELETE SET NULL ON UPDATE NO ACTION
    ) $charset_collate;";
    
    $wpdb->query($sql);
    
    //vidsrc_add_data();
    /*
    $wpdb->query( "DROP TABLE IF EXISTS ".$table_name_vidsrc_indb );
    $charset_collate = $wpdb->get_charset_collate() . ' engine = innoDB';
    
    $sql = "
    CREATE TABLE IF NOT EXISTS $table_name_vidsrc_indb (
        `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `post_id` BIGINT(20) UNSIGNED NOT NULL ,
        `imdb` VARCHAR(15) NULL DEFAULT NULL ,
        `tmdb` INT NULL DEFAULT NULL ,
        `season` INT NULL DEFAULT NULL ,
        `episode` INT NULL DEFAULT NULL ,
        `unique_md5` VARCHAR(32) AS(
            MD5(
                CONCAT(
                    `imdb`,
                    IF(
                        `season` IS NOT NULL ,
                        CONCAT('_', `season`, 'x', `episode`) ,
                        ''
                    )
                )
            )
        ) VIRTUAL ,
        `unique_md5_tmdb` VARCHAR(32) AS(
            md5(
                concat(`tmdb`,'_',`season`,'_',`episode`)
            )
        ) VIRTUAL ,
        UNIQUE INDEX (`unique_md5`) ,
        UNIQUE INDEX (`unique_md5_tmdb`) ,
        INDEX (`post_id`) ,
        INDEX (`imdb`) ,
        INDEX (`tmdb`) ,
        INDEX (`imdb` , `season` , `episode`) ,
        INDEX (`tmdb` , `season` , `episode`) ,
        FOREIGN KEY (`post_id`) 
        REFERENCES `".$wpdb->posts."`(`ID`) 
        ON DELETE CASCADE ON UPDATE NO ACTION
    ) $charset_collate;";
    
    $wpdb->query($sql);
    
    //vidsrc_add_data_indb();
    
    //require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    //dbDelta( $sql );
    */
	
	update_option("vidsrc_active",'');
	update_option("vidsrc_cron",'off');
	update_option("vidsrc_movies_no",'10');
	update_option("vidsrc_episodes_no",'50');
    update_option("vidsrc_sync",'0');
    update_option("vidsrc_sync_indb",'0');
    
	update_option("vidsrc_pluginversion",'1.0');

	}
}

function vidsrc_unistall() {
    
    
	global $wpdb;

    $table_name = $wpdb->prefix . "vidsrc";     
    
    $wpdb->query( "DROP TABLE IF EXISTS ".$table_name );
    
    $table_name_indb = $wpdb->prefix . "vidsrc_indb";     
    
    $wpdb->query( "DROP TABLE IF EXISTS ".$table_name_indb );
    
	delete_option("vidsrc_active");
	delete_option("vidsrc_cron");
	delete_option("vidsrc_player_color");
	delete_option("vidsrc_movies_no");
	delete_option("vidsrc_episodes_no");
	delete_option("vidsrc_sync");
	delete_option("vidsrc_sync_indb");
	delete_option("vidsrc_pluginversion");
	
	unregister_setting( 'vidsrc_settings', 'vidsrc_active' );
	unregister_setting( 'vidsrc_settings', 'vidsrc_cron' );
	unregister_setting( 'vidsrc_settings', 'vidsrc_player_color' );
	unregister_setting( 'vidsrc_settings', 'vidsrc_movies_no' );
	unregister_setting( 'vidsrc_settings', 'vidsrc_episodes_no' );
	unregister_setting( 'vidsrc_settings', 'vidsrc_sync' );
	unregister_setting( 'vidsrc_settings', 'vidsrc_sync_indb' );
	
}

function vidsrc_adminmenu() {
	add_menu_page( 'VidSrc PsyPlay', 'VidSrc PsyPlay', 'manage_options', 'vidsrc_psyplay', 'vidsrc_settings_page', '','Dashboard' );

}

function vidsrc_plugin_activation() {
	$vidsrc_version = get_option( 'vidsrc_pluginversion' );
 
	if( version_compare($vidsrc_version, vidsrc_PLUGIN_VERSION(), '<')) {
	//update if need+
	}
 
	update_option( 'vidsrc_pluginversion', vidsrc_PLUGIN_VERSION() );
	return vidsrc_PLUGIN_VERSION();
}

function cron_add_limit($vidsrc_k){
	if($vidsrc_k == 'm'){
		$vidsrc_m = get_option('vidsrc_movies_no');
		$vidsrc_nGl = (5*20)+1;
		if($vidsrc_m >=$vidsrc_nGl){
			return $vidsrc_nGl-1;
		}else{
		 return $vidsrc_m;
		}
	}
	if($vidsrc_k == 'e'){
		$vidsrc_e = get_option('vidsrc_episodes_no');
		$vidsrc_nGl = (5*40)+1;
		if($vidsrc_e >=$vidsrc_nGl){
			return $vidsrc_nGl-1;
		}else{
		 	return $vidsrc_e;
		}
	}
}

function vidsrc_plugin_current_version(){
    $vidsrc_version = get_option( 'vidsrc_pluginversion' );
	if($vidsrc_version == false) 
	{
		return true;
	} 
	else 
	{
    return version_compare($vidsrc_version, '1.0', '=') ? true : false;
	}
}
function vidsrc_PLUGIN_VERSION() {
	return '1.0';
}



register_activation_hook( __FILE__, 'vidsrc_install' );
register_deactivation_hook( __FILE__, 'vidsrc_unistall' );



register_activation_hook(__FILE__, 'vidsrc_cronjob_activation');
register_deactivation_hook(__FILE__, 'vidsrc_cronjob_deactivation');



function vidsrc_cronjob_activation() {
    if (! wp_next_scheduled ( 'vidsrc_add_data_event' )) {
        if($vidsrc_cron_time != 'off'){
			wp_schedule_event(time(), '1min', 'vidsrc_add_data_event');
        }
    }
    if (! wp_next_scheduled ( 'vidsrc_add_data_indb_event' )) {
        if($vidsrc_cron_time != 'off'){
			wp_schedule_event(time(), '15min', 'vidsrc_add_data_indb_event');
        }
    }
    if (! wp_next_scheduled ( 'vidsrc_add_data_latest_event' )) {
        if($vidsrc_cron_time != 'off'){
			wp_schedule_event(time(), '15min', 'vidsrc_add_data_latest_event');
        }
    }
    
    if (! wp_next_scheduled ( 'vidsrc_movies_event' )) {
	    $vidsrc_cron_time = get_option('vidsrc_cron');
	    if($vidsrc_cron_time != 'off'){
			wp_schedule_event(time(), $vidsrc_cron_time.'min', 'vidsrc_movies_event');
		}	
    }
    if (! wp_next_scheduled ( 'vidsrc_tvshow_event' )) {
	    $vidsrc_cron_time = get_option('vidsrc_cron');
	    if($vidsrc_cron_time != 'off'){
			wp_schedule_event(time(), $vidsrc_cron_time.'min', 'vidsrc_tvshow_event');
		}	
    }
    
    if (! wp_next_scheduled ( 'vidsrc_clean_duplicates_event' )) {
        if($vidsrc_cron_time != 'off'){
			wp_schedule_event(time(), '10h', 'vidsrc_clean_duplicates_event');
        }
    }
    
    if (! wp_next_scheduled ( 'vidsrc_update_player_color_event' )) {
        if($vidsrc_cron_time != 'off'){
			wp_schedule_event(time(), '30min', 'vidsrc_update_player_color_event');
        }
    }
    
    if (! wp_next_scheduled ( 'vidsrc_clean_dead_titles_event' )) {
        if($vidsrc_cron_time != 'off'){
			wp_schedule_event(time(), 'twicedaily', 'vidsrc_clean_dead_titles_event');
        }
    }
    
    if (! wp_next_scheduled ( 'vidsrc_ping_active_event' )) {
        if($vidsrc_cron_time != 'off'){
			wp_schedule_event(time(), 'twicedaily', 'vidsrc_ping_active_event');
        }
    }
    
    
    
    
    
    
}

add_action('vidsrc_add_data_event', 'vidsrc_add_data');
add_action('vidsrc_add_data_indb_event', 'vidsrc_add_data_indb');
add_action('vidsrc_add_data_latest_event', 'vidsrc_add_data_latest');
add_action('vidsrc_movies_event', 'vidsrc_do_action_for_movies');
add_action('vidsrc_tvshow_event', 'vidsrc_do_action_for_tvshows');
add_action('vidsrc_clean_duplicates_event', 'vidsrc_clean_dupl');
add_action('vidsrc_update_player_color_event', 'updatePlayerColor');
add_action('vidsrc_clean_dead_titles_event', 'vidsrc_clean_dead_titles');
add_action('vidsrc_ping_active_event', 'pingVidsrc');



function vidsrc_cronjob_deactivation() {
    wp_clear_scheduled_hook('vidsrc_add_data_event');
    wp_clear_scheduled_hook('vidsrc_add_data_indb_event');
    wp_clear_scheduled_hook('vidsrc_add_data_latest_event');
	wp_clear_scheduled_hook('vidsrc_movies_event');
	wp_clear_scheduled_hook('vidsrc_tvshow_event');
	wp_clear_scheduled_hook('vidsrc_clean_duplicates_event');
	wp_clear_scheduled_hook('vidsrc_update_player_color_event');
    wp_clear_scheduled_hook('vidsrc_clean_dead_titles_event');
    wp_clear_scheduled_hook('vidsrc_ping_active_event');

}



function vidsrc_custom_cron_schedules($vidsrc_schedules){
    if(!isset($vidsrc_schedules["1min"])){
        $vidsrc_schedules["1min"] = array(
            'interval' => 1*60,
            'display' => __('Once every 1 minutes'));
    }
    if(!isset($vidsrc_schedules["2min"])){
        $vidsrc_schedules["2min"] = array(
            'interval' => 2*60,
            'display' => __('Once every 2 minutes'));
    }
    if(!isset($vidsrc_schedules["5min"])){
        $vidsrc_schedules["5min"] = array(
            'interval' => 5*60,
            'display' => __('Once every 5 minutes'));
    }
    if(!isset($vidsrc_schedules["15min"])){
        $vidsrc_schedules["15min"] = array(
            'interval' => 15*60,
            'display' => __('Once every 15 minutes'));
    }
    if(!isset($vidsrc_schedules["30min"])){
        $vidsrc_schedules["30min"] = array(
            'interval' => 30*60,
            'display' => __('Once every 30 minutes'));
    }
    return $vidsrc_schedules;
}

add_filter('cron_schedules','vidsrc_custom_cron_schedules');
if ( is_admin() ){
	$vidsrc_plugin = plugin_basename(__FILE__); 
	add_action( 'admin_init', 'vidsrc_register_mysettings' );
	add_filter( 'plugin_action_links_'.$vidsrc_plugin, 'vidsrc_settings_link', 10, 2);
	add_action( 'admin_menu', 'vidsrc_adminmenu' );
}


?>
