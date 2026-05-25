<?php

return array(
    'id' =>             'osticket:ai-response-suggester',
    'version' =>        '1.1.0',
    'name' =>           'AI Response Suggester',
    'description' =>    'AI-powered response suggestions combining canned responses, ticket context, and crawled knowledge base content.',
    'author' =>         'osTicket AI',
    'ost_version' =>    MAJOR_VERSION,
    'plugin' =>         'src/Plugin.php:AIResponseSuggesterPlugin',
    'include_path' =>   '',
    'url' =>            'https://github.com/osTicket-AI/ai-response-suggester',
);
