<?php
/**
* Default host is localhost
* Default port is 80
* 
* Written by Jason E Rush ( Jason@Jason-Rush.com )
* http://Projects.Jason-Rush.com/
* 
*/
class akProtoRouter{
    /**************\
    | Private vars |
    \**************/
    private $clients = array();
    private $buffer = array();
    private $listener = false;
    private $numClients = 0;
    
    private $routes = array();
    private $default = stdClass;
    private $fallback = null;

    private $dbg = false;

    private $selectSec = 2;
    private $selectUsec = 0;

    /*************\
    | Public vars |
    \*************/
    public $error = "";
    public $active = false;
    
    /*******************\
    | Private functions |
    \*******************/
    private function debug( $msg ){
        if( $this->dbg )
            echo $msg."\n";
    }

    /******************\
    | Public functions |
    \******************/
    public function __construct(){
         $this->default = (object) array( "port" => 80, "host" => "localhost", "caseSensitive" => 1 );
    }
    
    public function getClients(){
        $clients = $this->clients;
        $retClients = array();
        foreach( $clients as $client ){
            unset( $client->connTime, $client->srcSock, $client->dstSock, $client->srcBuff, $client->dstBuff );
            $retClients[] = $client;
        }
    }
    /* Debug functions */
    public function debugOn(){
        $this->dbg = true;
    }
    public function debugOff(){
        $this->dbg = false;
    }
    /* Setting timeouts for select() call */
    public function setTimeout( $sec = 2, $usec = null ){
        $this->selectSec = $sec;
        if( $usec !== null )
            $this->selectUsec = $usec;
    }
    /* Route Handling */
    public function getRoutes(){
        return $this->routes;
    }

    public function addRoute( $str, $options = array() ){
        $opts = New stdClass();
        $opts->host = ( isset( $options['host'] ) ? $options['host'] : $this->default->host );
        $opts->port = ( isset( $options['port'] ) ? $options['port'] : $this->default->port );
        $opts->caseSensitive = ( isset( $options['caseSensitive'] ) ? $options['caseSensitive'] : $this->default->caseSensitive );
        $this->routes[ $str ] = $opts;
    }

    public function setFallback( $options ){
        $opts = New stdClass();
        if( isset( $options['host'] ) )
            $opts->host = $options['host'];
        if( isset( $options['port'] ) )
            $opts->port = $options['port'];
        if( isset( $options['timeout'] ) )
            $opts->timeout = $options['timeout'];
        $this->fallback = $opts;
    }
    /* Handling clients */
    public function removeClient( $c ){
        if( is_resource( $c->srcSock ) )
            socket_close( $c->srcSock );
        if( is_resource( $c->dstSock ) )
            socket_close( $c->dstSock );
        unset( $this->clients[ array_search( $c, $this->clients ) ] );
    }
    public function addClient( $sock ){
        $this->debug( "Attempting to add client" );
        $c = New stdClass;
        $c->connTime = time();
        $c->srcSock = $sock;
        $c->dstSock = false;
        $c->srcBuff = '';
        $c->dstBuff = '';
        $c->dstHost = null;
        $c->dstPort = null;
        $c->forwarded = false;
        if( socket_getpeername( $sock, $addr, $port) ){
            $c->host = $addr;
            $c->port = $port;
        }else{
            $c->host = 'Unknown';
            $c->port = 0;
        }
        $this->clients[] = $c;
        $this->debug( "Added client:\t\t".$addr.":".$port );
    }

    /* Set up listener */
    public function start( $port ){
        $this->debug( "Creating listener" );
        // Create listener
        if( false === ( $this->listener = socket_create_listen( $port, SOMAXCONN ) ) ){
            $this->error = socket_strerror( socket_last_error() );
            return false;
        }
        // Set the IP address ready for reuse (not sure if this one is necessary)
        if ( ! socket_set_option($this->listener, SOL_SOCKET, SO_REUSEADDR, 1) ) { 
            $this->error = socket_strerror( socket_last_error() ); 
            return false;
        }
        // Set the Port ready for reuse, so we don't get the "Port is already in use" error
        if ( ! socket_set_option($this->listener, SOL_SOCKET, SO_LINGER, array( 'l_onoff' => 1, 'l_linger' => 0 ) ) ) { 
            $this->error = socket_strerror( socket_last_error() ); 
            return false;
        }
        $this->active = true;
        return true;
    }

    /* Main Looping Function */
    public function loop(){
        // This way we can confirm whether it returned with some type of exit code, or if it exited normally
        if( $this->active === false )
            return false;
        $this->active = false;
        
        // Just keeping track of number of clients, for debugging
        if( count( $this->clients ) != $this->numClients )
            $this->debug( "Clients: ".count( $this->clients ) );
        $this->numClients = count( $this->clients );
        
        // Set up arrays for select() call
        $r = array();
        $w = array();
        $e = array();
        if( is_resource( $this->listener ) )
            $r[] = $this->listener;
        foreach( $this->clients as $client ){
            if( is_resource( $client->srcSock ) ){
                $r[] = $client->srcSock;
                $e[] = $client->srcSock;
            }
            if( strlen( $client->srcBuff ) > 0 )
                $w[] = $client->srcSock;
            if( is_resource( $client->dstSock ) ){
                $r[] = $client->dstSock;
                $e[] = $client->dstSock;
            }
            if( strlen( $client->dstBuff ) > 0 )
                $w[] = $client->dstSock;
        }
        // The actual select() call
        if( false === @socket_select( $r, $w, $e, $this->selectSec, $this->selectUsec ) ){
            $this->error = socket_strerror( socket_last_error() );
            return false;
        }
        // If there are any new clients trying to connect, lets take care of them
        if( in_array( $this->listener, $r ) ){
                $this->addClient( socket_accept( $this->listener ) );
        }
        // Cycle through each client, and check if each src and dst sock is in one of the r,w,e arrays
        foreach( $this->clients as $c ){
            $this->debug( $c->host.":".$c->port."\t=>\t".$c->dstHost.":".$c->dstPort );
            if( is_resource( $c->srcSock ) ){
                if( in_array( $c->srcSock, $e ) ){
                    $this->debug( "!!! Exception !!!" );
                }

                // Fall back if there *is* a fallback, and we have passed our fallback-timeout
                if( ! $c->forwarded && isset( $this->fallback->timeout, $this->fallback->host, $this->fallback->port ) && ! in_array( $c->srcSock, $r ) ){
                    if( (time() - $c->connTime ) >= $this->fallback->timeout ){
                        $this->debug( "Falling back to default" );
                        $newSock = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
                        if( false !== $newSock ){
                            if( socket_connect( $newSock, $this->fallback->host, $this->fallback->port ) ){
                                $c->dstSock = $newSock;
                                $c->forwarded = true;
                                $c->dstHost = $this->fallback->host;
                                $c->dstPort = $this->fallback->port;
                                $this->debug( "Connected to fallback connection" );
                            }else{
                                socket_close( $newSock );
                                $this->debug( "Fallback failed" );
                            }
                        }
                    }
                }
                // If source socket is readable
                if( in_array( $c->srcSock, $r ) ){
                    // Read from the socket, and store in the opposite buffer
                    $tmpRead = socket_read( $c->srcSock, 9000 );
                    $c->dstBuff .= $tmpRead;

                    // If it read zero bytes, then the client disconnected
                    if( strlen( $tmpRead ) == 0 ){
                        $this->debug( "Client disconnected!" );
                        $this->removeClient( $c );
                        break;
                    }
                    // If we have not yet figured out a proper destination for the client yet, lets check out possibilities
                    if( ! $c->forwarded && strlen( $tmpRead ) > 0 ){
                        $matches = 0;
                        foreach( $this->routes as $start => $opt ){
                            // First check if it's a full match
                            if( strlen( $c->dstBuff ) > strlen( $start ) ){
                                if( ( $opt->caseSensitive && substr( $c->dstBuff, 0, strlen( $start ) ) == $start )
                                    || ( ! $opt->caseSensitive
                                        && strtolower( substr( $c->dstBuff, 0, strlen( $start ) ) ) == strtolower( $start ) )
                                    ){
                                    $this->debug( "FULL MATCH!\t<$start>\t".$this->routes[$start]->host.":".$this->routes[$start]->port );
                                    $newSock = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
                                    if( false !== $newSock ){
                                        if( socket_connect( $newSock, $this->routes[$start]->host, $this->routes[$start]->port ) ){
                                            $c->dstSock = $newSock;
                                            $c->forwarded = true;
                                            $c->dstHost = $this->routes[$start]->host;
                                            $c->dstPort = $this->routes[$start]->port;
                                        }else
                                            socket_close( $newSock );
                                    }
                                }
                            // Otherwise, check if it's a partial/potential
                            }else{
                                if( ( $opt->caseSensitive && $c->dstBuff == substr( $start, 0, strlen( $c->dstBuff ) ) )
                                    || ( ! $opt->caseSensitive
                                        && strtolower($c->dstBuff) == strtolower(substr( $start, 0, strlen( $c->dstBuff ) ) ) )
                                    ){
                                    $this->debug( "Potential match: =$start= ={$c->dstBuff}=" );
                                    $matches++;
                                }
                            }
                        }
                        // If it does not have any full or potential matches, then it's an unsupported protocol
                        // so we'll just drop the connection
                        if( $matches == 0 && $c->forwarded == false ){
                            if( is_resource( $c->srcSock ) )
                                socket_close( $c->srcSock );
                            $this->debug( "No matches" );
                            // and lets show a partial string for debugging to see what clients are connecting with
                            if( strlen( $c->dstBuff ) > 0 )
                                $this->debug( "\t=>".substr( $c->dstBuff, 0, 10 )."<=" );
                            
                        }
                    }
                }
            }
            // If we have a destination connected, lets try reading from it
            if( is_resource( $c->dstSock ) ){
                if( in_array( $c->dstSock, $r ) ){
                    $tmpRead = socket_read( $c->dstSock, 9000 );
                    $c->srcBuff .= $tmpRead;

                    if( strlen( $tmpRead ) == 0 ){
                        $this->debug( "Client disconnected" );
                        $this->removeClient( $c );
                        break;
                    }
                }
            }
            // If we have have something in our source buffer, lets try writing to the client conn
            if( is_resource( $c->srcSock ) ){
                if( in_array( $c->srcSock, $w ) && strlen( $c->srcBuff ) > 0 ){
                    $writeLen = socket_write( $c->srcSock, $c->srcBuff );
                    if( $writeLen === false ){
                        $this->error = socket_strerror( socket_last_error() );
                        return false;
                    }
                    $c->srcBuff = substr( $c->srcBuff, $writeLen );
                }
            }
            // If we have have something in our destination buffer, lets try writing to the server conn
            if( is_resource( $c->dstSock ) ){
                if( in_array( $c->dstSock, $w ) && strlen( $c->dstBuff ) > 0 ){
                    $writeLen = socket_write( $c->dstSock, $c->dstBuff );
                    if( $writeLen === false ){
                        $this->error = socket_strerror( socket_last_error() );
                        return false;
                    }
                    $c->dstBuff = substr( $c->dstBuff, $writeLen );
                }
            }
        }
        // We have made it to the end, it was a successful loop
        $this->active = true;
        return true;
    }

    // Loop through each and every connection, and close them, and de-activate the itteration
    public function stop(){
        if( is_resource( $c->listener ) )
            socket_close( $this->listener );
        foreach( $this->clients as $c )
            $this->removeClient( $c );
        $this->active = false;
    }
}
?>