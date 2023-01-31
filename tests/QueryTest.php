<?php

declare(strict_types=1);

require_once "classes.php";

use GraphQL\GraphQL;
use GraphQL\Error\DebugFlag;

class QueryTest extends \PHPUnit\Framework\TestCase
{
    public function testQuery()
    {
        $schema = \GQLA\genSchema();
        $debug = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::RETHROW_INTERNAL_EXCEPTIONS;
        $resp = GraphQL::executeQuery(
            $schema,
            '
{
    posts {
        title,
        author {
            name
        },
        comments {
            text
            author {
                name
            }
        }
    }
}'
        )->toArray($debug);
        $this->assertEquals(
            [
                'data' => [
                    'posts' => [
                        0 => [
                            'title' => 'Hello world!',
                            'author' => [
                                'name' => 'Admin',
                            ],
                            'comments' => [
                                0 => [
                                    'text' => 'Hi there :D',
                                    'author' => [
                                        'name' => 'Admin',
                                    ],
                                ],
                                1 => [
                                    'text' => 'I\'m anonymous o.o',
                                    'author' => null,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $resp,
            \var_export($resp, true)
        );
    }
}
