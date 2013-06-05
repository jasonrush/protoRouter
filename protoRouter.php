<?php
// include the class
include( 'protoRouter.class.php' );

// Set up signal handler to be able to gracefully exit with CTRL-C
declare(ticks = 1);
pcntl_signal(SIGINT, "sig_handler");
function sig_handler($signal){
    global $router;
    switch( $signal ){
        case SIGINT:
            $router->stop();
            die( "Terminated by user\n" );
            break;
    }
}

// Did we specify a port in the console? or default to 31337
$port = ( $argc > 1 ? (int) $argv[1] : 31337 );

// Set up which routes we want to use
$routes = array(

    /* HTTP */
    "GET " => array( 'port' => 80, 'host' => "hack3r.com", 'caseSensitive' => 0 ),
    "POST " => array( 'port' => 80, 'host' => "hack3r.com", 'caseSensitive' => 0 ),
    "HEAD " => array( 'port' => 80, 'host' => "hack3r.com", 'caseSensitive' => 0 ),

    /* SSL/TLS ??? untested... but this should work for HTTPS, etc*/
//    "\x16\x03\x00" => array( ), // these are various version of SSL/TLS
//    "\x16\x03\x01" => array( ), // http://en.wikipedia.org/wiki/Transport_Layer_Security#TLS_record_protocol
//    "\x16\x03\x02" => array( ),
//    "\x16\x03\x03" => array( ),

    /* IRC */
    "NICK " => array( 'port' => 6667, 'host' => "irc.hack3r.com", 'caseSensitive' => 0 ),
    "CAP LS" => array( 'port' => 6667, 'host' => "irc.hack3r.com", 'caseSensitive' => 0 ),

    /* TFTP ??? untested... */
//    "\x00\x01" => array( ),
//    "\x00\x02" => array( ),

    /* SOCKS 4/4a ??? untested... */
//    "\x04\x01" => array( ),
//    "\x04\x02" => array( ),

    /* SMTP ??? untested... */
//    "MAIL" => array( ),
//    "RCPT" => array( ),

    /* POP ??? untested... */
//    "USER" => array( ),

    /* FTPS ??? untested... */
//    "AUTH TLS" => array( ),

    /* RLOGIN ??? untested... */
//    "\x00" => array( ),
);

// Initialize the router instance, set up debugging, add the routes
$router = New akProtoRouter();
$router->debugOn();
foreach( $routes as $start => $options )
    $router->addRoute( $start, $options );

// If the client doesn't talk within 'timeout' seconds, fall back to this 'server-talks-first' connection
$router->setFallback( array( 'port' => 14229, 'host' => "localhost", 'timeout' => 5 ) );
// Select() times out after 1.5 seconds
$router->setTimeout( 1, 500 );

// Attempt to set up the listener
if( false === ( $status = $router->start( $port ) ) && strlen( $router->error ) > 0 )
    die("ERROR: ".$router->error."\n");

// Keep looping through until we get an error, or manually close with our signal handler
while( $router->active ){
    if( false === ( $status = $router->loop() ) && strlen( $router->error ) > 0 )
        die("ERROR: ".$router->error."\n");
}

echo "Exited normally.";

?>