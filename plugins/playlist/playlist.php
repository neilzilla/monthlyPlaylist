<?php

    kirby()->routes(array(
        array(
            'pattern' => 'plugins/playlist-update',
            'action'  => function() {
                pl_full_update();
            }
        ),
        array(
            'pattern' => 'playlist',
            'action'  => function() {
                if(pl_check_details()) go('error');
                go('https://open.spotify.com/embed?uri=spotify:user:jimmyscoot:playlist:6ZaUvz0kxkhaUq2PTqjDxB&theme=white');
            }
        )
    ));
    kirby()->hook('panel.page.update', function($page) {
        if($page->uri() == 'playlist') pl_panel_update();
    });

    function pl_check_details(){
        $page = page('playlist');
        $error = array();
        if($page){
            if($page->lastfm_token()->empty()) $error[] = 'Last.fm Token Missing';
            if($page->lastfm_user()->empty()) $error[] = 'Last.fm User Missing';
            if($page->client_id()->empty()) $error[] = 'Spotify Client ID Missing';
            if($page->client_secret()->empty()) $error[] = 'Spotify Client Secret Missing';
            if($page->refresh_token()->empty()) $error[] = 'Spotify Refresh Token Missing';
            if($page->playlist_id()->empty()) $error[] = 'Spotify Playlist ID Missing';
            if($page->user_id()->empty()) $error[] = 'Spotify User ID Missing';
        }else{
            $error[] = "Please create 'Playlist' page";
        }
        return $error;
    }



    function pl_full_update(){
        $error = pl_check_details();
        if(count($error)){ 
            foreach($error as $e) echo $e . "<br />";
        }else{
        
            $page = page('playlist');

            // Get Access Token from Refresh
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,            'https://accounts.spotify.com/api/token' );
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt($ch, CURLOPT_POST,           1 );
            curl_setopt($ch, CURLOPT_POSTFIELDS,     'grant_type=refresh_token&refresh_token=' . $page->refresh_token()); 
            curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Authorization: Basic '.base64_encode($page->client_id() . ':' . $page->client_secret()))); 
            $result = json_decode(curl_exec($ch), true);

            // Update Access Token
            $page->update(array('access_token' => $result['access_token']));

            // Get Top Tracks last month
            $tracks = json_decode(file_get_contents('http://ws.audioscrobbler.com/2.0/?method=user.gettoptracks&user=' . $page->lastfm_user() . '&api_key=' . $page->lastfm_token() . '&period=1month&limit=20&format=json'), true);

            // Delete Existing Playlist
            foreach($page->children() as $p) $p->delete();

            $tracks = $tracks['toptracks']['track'];
            foreach($tracks as $trackno => $track){
                $t['artist'] = $track['artist']['name'];
                $t['name'] = $track['name'];

                curl_setopt($ch, CURLOPT_URL,            'https://api.spotify.com/v1/search?q=track:' . urlencode($t['name']) . '%20artist:' . urlencode($t['artist']) . '&type=track&limit=1' );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
                curl_setopt($ch, CURLOPT_POST,           0 );
                curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Authorization: Bearer ' . $page->access_token()));
                $result = json_decode(curl_exec($ch), true);

                $t['id'] = $result['tracks']['items'][0]['id'];

                    try {
                $page->create('playlist/' . $trackno . '-' . $t['artist'] . '-' . $t['name'], 'song', array(
                    'artist' => $t['artist'],
                    'name' => $t['name'],
                    'spotify_id' => $t['id']
                  ));
                } catch(Exception $e) {

                  echo $e->getMessage();

                }

                $playlist_tracks[] = $t;
            }

            $uris = '';
            foreach($playlist_tracks as $p){
                $uris .= 'spotify:track:' . $p['id'] . ',';
            }

            $uris = 'uris=' . urlencode(substr($uris, 0, -1));

            curl_setopt($ch, CURLOPT_URL,            'https://api.spotify.com/v1/users/' . $page->user_id() . '/playlists/' . $page->playlist_id() . '/tracks?' . $uris );
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Authorization: Bearer ' . $page->access_token()));
            curl_exec($ch);

            $new_name = json_encode((object)array("name" => "Top Tracks for " . date('F Y', strtotime('first day of previous month'))));

            $page->update(array('playlist_name' => "Top Tracks for " . date('F Y', strtotime('first day of previous month'))));


            curl_setopt($ch, CURLOPT_URL,            'https://api.spotify.com/v1/users/' . $page->user_id() . '/playlists/' . $page->playlist_id());
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS,     $new_name); 
            curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Authorization: Bearer ' . $page->access_token()));
            $result = json_decode(curl_exec($ch), true);
        }
    }

    function pl_panel_update(){
        $page = page('playlist');
        
        $uris = '';
        foreach($page->children() as $song){
            $uris .= 'spotify:track:' . $song->spotify_id() . ',';
        }

        $uris = 'uris=' . urlencode(substr($uris, 0, -1));    
      
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            'https://api.spotify.com/v1/users/' . $page->user_id() . '/playlists/' . $page->playlist_id() . '/tracks?' . $uris );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Authorization: Bearer ' . $page->access_token()));
        curl_exec($ch);
        
        curl_setopt($ch, CURLOPT_URL,            'https://api.spotify.com/v1/users/' . $page->user_id() . '/playlists/' . $page->playlist_id());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode((object)array("name" => (string)$page->playlist_name()))); 
        curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Authorization: Bearer ' . $page->access_token()));
        $result = json_decode(curl_exec($ch), true);
        
    }
?>
