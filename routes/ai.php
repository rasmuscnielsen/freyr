<?php

use App\Mcp\Servers\FreyrServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', FreyrServer::class)->middleware('auth:api');

Mcp::local('freyr', FreyrServer::class);
