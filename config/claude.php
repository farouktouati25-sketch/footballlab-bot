<?php

return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('CLAUDE_MODEL', 'claude-sonnet-5-5'),
    'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 700),
    // How many past messages the agent sees per reply.
    'history_limit' => (int) env('CLAUDE_HISTORY_LIMIT', 24),
    // Max tool-use round trips per inbound message.
    'max_tool_rounds' => (int) env('CLAUDE_MAX_TOOL_ROUNDS', 6),
];
