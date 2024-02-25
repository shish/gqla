<?php

declare(strict_types=1);

require_once "classes.php";

use GraphQL\GraphQL;
use GraphQL\Error\DebugFlag;

class QueryTest extends \PHPUnit\Framework\TestCase
{
    public function testQuery(): void
    {
        $schema = new \GQLA\Schema();
        $debug = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::RETHROW_INTERNAL_EXCEPTIONS;
        $resp = GraphQL::executeQuery(
            $schema,
            '
{
    posts {
        title,
        state,
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
                            'state' => 'Published',
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

    public function testInputObject(): void
    {
        $schema = new \GQLA\Schema();
        $debug = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::RETHROW_INTERNAL_EXCEPTIONS;
        $resp = GraphQL::executeQuery(
            $schema,
            'mutation createUser {
                create_user(input: { username: "newuser", password: "waffo", email: "foo@bar.com" }) {
                    id
                    name
                }
              }'
        )->toArray($debug);
        $this->assertEquals(
            [
                'data' => [
                    'create_user' => [
                        'id' => 'user:42',
                        'name' => 'newuser',
                    ],
                ],
            ],
            $resp,
            \var_export($resp, true)
        );
    }

    public function testTopLevelFunction(): void
    {
        $schema = new \GQLA\Schema();
        $debug = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::RETHROW_INTERNAL_EXCEPTIONS;
        $resp = GraphQL::executeQuery(
            $schema,
            'mutation createUser {
                login(username: "Admin", password: "admin") {
                    id
                    name
                }
              }'
        )->toArray($debug);
        $this->assertEquals(
            [
                'data' => [
                    'login' => [
                        'id' => 'user:1',
                        'name' => 'Admin',
                    ],
                ],
            ],
            $resp,
            \var_export($resp, true)
        );
    }
}
