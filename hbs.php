#!/usr/bin/php
<?php
define( "SCOPUS_API_KEY", "SET YOUR API KEY HERE" );
define( "IEEE_API_KEY", "SET YOUR API KEY HERE" );
define( "TITLE", 0 );
define( "SOURCE", 1 );
define( "AUTHORS", 2 );
define( "YEAR", 3 );
define( "DOI", 4 );
define( "SEARCH_TERMS", 5 );
define( "RANK", 6 );
define( "DATE", 7 );

function message( $message, $verbose = false ) {
    global $args;

    if (
        ! $verbose
        || $args["verbose"]
    ) {
        print( $message . PHP_EOL );
    }
}

function get_article_index( $article ) {
    if ( ! empty( $article[DOI] ) ) {
        $hash_seed = $article[DOI];
    } else {
        $hash_seed = $article[TITLE] . current( $article[AUTHORS] ) . $article[YEAR];
    }

    return md5( $hash_seed );
}

function parse_arg_settings( $settings ) {
    return array_merge(
        [
            "default" => null,
            "help" => null,
            "required" => false,
            "use_value" => false,
            "validate" => null,
        ],
        $settings
    );
}

function start_progress( $title, $total ) {
    global $progress_total, $progress_title, $progress;

    $progress_total = $total;
    $progress_title = $title;
    $progress = 0;

    print_progress( 0 );
}

function print_progress( $progress ) {
    global $progress_title;

    printf( $progress_title . " [" . round( $progress * 100, 2 ) . "%%]   \r" );
}

function update_progress() {
    global $progress_total, $progress;

    $progress++;

    print_progress( $progress_total ? $progress / $progress_total : 0 );
}

function finish_progress() {
    print_progress( 1 );

    printf( PHP_EOL );
}

function parse_doi( $doi ) {
    $parsed_doi = "";

    if ( ! empty( $doi ) ) {
        $parsed_doi = "https://doi.org/" . $doi;
    }

    return $parsed_doi;
}

function request_url( $url ) {
    global $args;

    $cache_file = sys_get_temp_dir() . "/scrapper-" . md5( $url ) . ".tmp";
    $response = null;

    if (
        $args["use_cache"]
        && file_exists( $cache_file )
        && filemtime( $cache_file ) > time() - ( 24 * 60 * 60 )
    ) {
        $response = file_get_contents( $cache_file );
    }

    if ( null === $response ) {
        $attempts = 0;

        do {
            message( "URL" . ( $attempts > 0 ? " (" . ( $attempts + 1 ) . "/" . $args["max_attempts"] . ")" : "" ) . ": " . $url, true );

            $response = file_get_contents( $url );

            if ( false !== $response ) {
                file_put_contents( $cache_file, $response );

                break;
            } else {
                $response = null;

                sleep( 1 );
            }

            $attempts++;
        } while ( $attempts < $args["max_attempts"] );
    } else {
        message( "Retrieving from cache for URL: " . $url, true );
    }

    if (
        null === $response
        && ! $args["ignore_failed_calls"]
    ) {
        throw new Exception( "Error calling URL " . $url );
    }

    return $response;
}

function parse_multiple_argument( $name ) {
    global $args;

    return array_filter(
        array_map(
            'trim',
            explode( PHP_EOL, $args[ $name ] )
        )
    );
}

$apis = [
    // @link https://developer.ieee.org/docs/read/Searching_the_IEEE_Xplore_Metadata_API
    "IEEEXplore" => [
        "parse_articles" => function( $response ) {
            $articles = [];

            if ( ! empty( $response->articles ) ) {
                foreach ( $response->articles as $raw_article ) {
                    $article = [];

                    $article[TITLE] = $raw_article->title;
                    $article[AUTHORS] = array_map(
                        function( $author ) {
                            return $author->full_name;
                        },
                        $raw_article->authors->authors
                    );
                    $article[YEAR] = $raw_article->publication_year;
                    $article[DOI] = parse_doi( $raw_article->doi ?? null );

                    $articles[] = $article;
                }
            }

            return $articles;
        },
        "parse_total" => function( $response ) {
            return $response->total_records;
        },
        "request_mask" => sprintf(
            "http://ieeexploreapi.ieee.org/api/v1/search/articles?apikey=%s&format=json&max_records={count}&start_record={start}&index_terms={search_terms}&start_year={start_year}&end_year={end_year}",
            rawurlencode( IEEE_API_KEY )
        ),
    ],
    // @link https://www.ncbi.nlm.nih.gov/books/NBK25499/#chapter4.ESearch
    "PubMed" => [
        "parse_arguments" => [
            "search_terms" => function( $value ) {
                return preg_replace(
                    "/\"[^\"]+\"/",
                    "$0[All Fields]",
                    $value
                );
            },
        ],
        "parse_articles" => function( $response ) {
            $articles = [];
            $summary_response = request_url(
                sprintf(
                    "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&id=%s&retmode=json",
                    rawurlencode(
                        implode(
                            ",",
                            $response->esearchresult->idlist
                        )
                    )
                )
            );

            if ( $summary_response ) {
                $summary_response_decoded = json_decode( $summary_response );

                if ( $summary_response_decoded ) {
                    if ( ! empty( $summary_response_decoded->result ) ) {
                        $result = $summary_response_decoded->result;

                        foreach ( $result->uids as $id ) {
                            if ( isset( $result->$id ) ) {
                                $raw_article = $result->$id;

                                $article = [];
                                $doi = null;

                                if ( ! empty( $raw_article->articleids ) ) {
                                    foreach ( $raw_article->articleids as $articleid ) {
                                        if ( $articleid->idtype === "doi" ) {
                                            $doi = $articleid->value;

                                            break;
                                        }
                                    }
                                }

                                $article[TITLE] = $raw_article->title;
                                $article[AUTHORS] = array_map(
                                    function( $author ) {
                                        return $author->name;
                                    },
                                    $raw_article->authors
                                );
                                $article[YEAR] = date( "Y", strtotime( $raw_article->sortpubdate ) );
                                $article[DOI] = parse_doi( $doi );

                                $articles[] = $article;
                            }
                        }
                    }
                }
            }

            return $articles;
        },
        "parse_total" => function( $response ) {
            return $response->esearchresult->count;
        },
        "request_mask" => "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retstart={start}&retmax={count}&retmode=json&term={search_terms}&mindate={start_year}&maxdate={end_year}",
    ],
    // @link https://dev.elsevier.com/documentation/ScopusSearchAPI.wadl
    "Scopus" => [
        "parse_arguments" => [
            "end_year" => function( $value, $arguments ) {
                if ( ! empty( $arguments["start_year"] ) ) {
                    $value = "-" . $value;
                }

                return $value;
            },
        ],
        "parse_articles" => function( $response ) {
            $articles = [];

            if ( ! empty( $response->{"search-results"}->entry ) ) {
                foreach ( $response->{"search-results"}->entry as $entry ) {
                    if ( empty( $entry->error ) ) {
                        $article = [];
                        $authors = [];

                        if ( isset( $entry->{"dc:creator"} ) ) {
                            $authors[] = $entry->{"dc:creator"};
                        }

                        $article[TITLE] = $entry->{"dc:title"};
                        $article[AUTHORS] = $authors;
                        $article[YEAR] = date( "Y", strtotime( $entry->{"prism:coverDate"} ) );
                        $article[DOI] = parse_doi( $entry->{"prism:doi"} ?? null );

                        $articles[] = $article;
                    }
                }
            }

            return $articles;
        },
        "parse_total" => function( $response ) {
            return $response->{"search-results"}->{"opensearch:totalResults"};
        },
        "request_mask" => sprintf(
            "https://api.elsevier.com/content/search/scopus?apiKey=%s&httpAccept=application/json&count={count}&start={start}&query=KEY%%28{search_terms}%%29&date={start_year}{end_year}",
            rawurlencode( SCOPUS_API_KEY )
        ),
    ],
];

$possible_args = [
    "output" => [
        "help" => "Output file.",
        "required" => true,
        "use_value" => true,
    ],
    "search_terms" => [
        "help" => "Search terms.",
        "required" => true,
        "use_value" => true,
    ],
    "start_year" => [
        "default" => 1900,
        "help" => "Start year.",
        "use_value" => true,
    ],
    "end_year" => [
        "default" => date( "Y" ),
        "help" => "End year.",
        "use_value" => true,
    ],
    "max_attempts" => [
        "default" => 3,
        "help" => "Max reading attempts per external call.",
        "use_value" => true,
    ],
    "ignore_failed_calls" => [
        "help" => "Whether to continue if a call fails.",
    ],
    "use_cache" => [
        "help" => "Whether to retrieve recent calls from cache.",
    ],
    "api" => [
        "help" => sprintf(
            "API to use. Default all. Possible values are %s.",
            implode(
                ", ",
                array_map(
                    function( $api ) {
                        return sprintf( "\"%s\"", strtolower( $api ) );
                    },
                    array_keys( $apis )
                )
            )
        ),
        "use_value" => true,
        "validate" => function() use( $apis ) {
            $validate = true;
            $values = parse_multiple_argument( "api" );

            foreach ( $values as $value ) {
                if ( ! in_array( strtolower( $value ), array_map( 'strtolower', array_keys( $apis ) ) ) ) {
                    $validate = false;

                    break;
                }
            }

            return $validate;
        },
    ],
    "help" => [
        "help" => "Prints help.",
    ],
    "verbose" => [
        "help" => "Increases verbosity level.",
    ],
];

try {
    $articles = [];
    $total = 0;
    $api = null;
    $args = [];

    for ( $i = 0; $i < count( $argv ); $i++ ) {
        if ( preg_match( "/^--(.+)$/", $argv[ $i ], $matches ) ) {
            $arg = $matches[1];

            if ( ! isset( $possible_args[ $arg ] ) ) {
                throw new Exception( "Unknown argument \"" . $arg . "\"" );
            } else {
                $settings = parse_arg_settings( $possible_args[ $arg ] );

                if ( $settings["use_value"] ) {
                    if ( empty( $argv[ $i + 1 ] ) ) {
                        throw new Exception( "Missing value for argument \"" . $arg . "\"" );
                    }

                    $value = $argv[ $i + 1 ];

                    $i++;
                } else {
                    $value = true;
                }

                $args[ $arg ] = $value;
            }
        }
    }

    if ( ! empty( $args["help"] ) ) {
        message(
            sprintf(
                "Usage: %s%s%s",
                $argv[0],
                PHP_EOL,
                implode(
                    PHP_EOL,
                    array_map(
                        function( $arg, $settings ) {
                            $settings = parse_arg_settings( $settings );

                            return sprintf(
                                "  %-38s%s",
                                sprintf(
                                    $settings["required"] ? "%s" : "[%s]",
                                    sprintf(
                                        "--%s%s",
                                        $arg,
                                        $settings["use_value"] ? " <value>" : ""
                                    )
                                ),
                                $settings["help"]
                            );
                        },
                        array_keys( $possible_args ),
                        $possible_args
                    )
                )
            )
        );
    } else {
        foreach ( $possible_args as $arg => $settings ) {
            $settings = parse_arg_settings( $settings );

            if ( ! isset( $args[ $arg ] ) ) {
                if ( $settings["required"] ) {
                    throw new Exception( "Missing argument \"" . $arg . "\"" );
                } else {
                    $args[ $arg ] = $settings["default"];
                }
            } else {
                if ( $settings["validate"] ) {
                    $value = $args[ $arg ];

                    if ( ! $settings["validate"]( $value ) ) {
                        throw new Exception( "Invalid value \"" . $value . "\" for argument \"" . $arg . "\"" );
                    }
                }
            }
        }

        $output = $args["output"];

        $articles_by_provider = [];

        if ( file_exists( $output ) ) {
            $fp = fopen( $output, "r" );

            if ( $fp ) {
                while ( $article = fgetcsv( $fp ) ) {
                    $article[SOURCE] = explode( ",", $article[SOURCE] );
                    $article[AUTHORS] = explode( ",", $article[AUTHORS] );
                    $article[SEARCH_TERMS] = explode( ",", $article[SEARCH_TERMS] );
                    $article[RANK] = explode( ",", $article[RANK] );
                    $article[DATE] = explode( ",", $article[DATE] );

                    $id = get_article_index( $article );

                    $articles[ $id ] = $article;
                }

                fclose( $fp );
            }
        }

        $old_articles = count( $articles );
        $articles_added = 0;
        $articles_updated_map = [];
        $search_terms_array = parse_multiple_argument( "search_terms" );
        $selected_apis = array_map(
            "strtolower",
            parse_multiple_argument( "api" )
        );

        foreach ( $search_terms_array as $search_terms ) {
            foreach ( $apis as $source => $settings ) {
                if ( empty( $selected_apis ) || in_array( strtolower( $source ), $selected_apis, true ) ) {
                    message( "Calling API ". $source . " for search terms: \"" . $search_terms . "\"..." );

                    $processed_articles = 0;
                    $total = null;

                    do {
                        $placeholders = array_merge(
                            $args,
                            [
                                "count" => 25,
                                "search_terms" => $search_terms,
                                "start" => $processed_articles,
                            ]
                        );

                        $request = preg_replace_callback(
                            "/{([^}]+)}/",
                            function( $matches ) use ( $placeholders, $settings ) {
                                $argument = $matches[1];
                                $value = "";

                                if ( isset( $placeholders[ $argument ] ) ) {
                                    $value = $placeholders[ $argument ];

                                    if ( isset( $settings['parse_arguments'][ $argument ] ) ) {
                                        $value = $settings['parse_arguments'][ $argument ]( $value, $placeholders );
                                    }
                                }

                                return rawurlencode( $value );
                            },
                            $settings["request_mask"]
                        );

                        $response = request_url( $request );

                        if ( $response ) {
                            $decoded_response = json_decode( $response );

                            if ( $decoded_response ) {
                                if ( $total === null ) {
                                    $total = $settings["parse_total"]( $decoded_response );

                                    message( "Total articles: " . $total, true );

                                    start_progress( "Receiving articles...", $total );
                                }

                                $page_articles = $settings["parse_articles"]( $decoded_response );

                                foreach ( $page_articles as $page_article ) {
                                    update_progress();
    ;
                                    $processed_articles++;

                                    $id = get_article_index( $page_article );

                                    if ( ! isset( $articles[ $id ] ) ) {
                                        $article = [];

                                        $article[TITLE] = $page_article[TITLE];
                                        $article[SOURCE] = [];
                                        $article[AUTHORS] = $page_article[AUTHORS];
                                        $article[YEAR] = $page_article[YEAR];
                                        $article[DOI] = $page_article[DOI];
                                        $article[SEARCH_TERMS] = [];
                                        $article[RANK] = [];
                                        $article[DATE] = [];

                                        $articles_added++;
                                    } else {
                                        $article = $articles[ $id ];

                                        $articles_updated_map[ $id ] = true;
                                    }

                                    $source_index = -1;

                                    foreach ( $article[SOURCE] as $maybe_source_index => $article_source ) {
                                        if (
                                            $article_source === $source
                                            && $article[SEARCH_TERMS][ $maybe_source_index ] === $search_terms ) {
                                            $source_index = $maybe_source_index;
                                        }
                                    }

                                    $rank = $processed_articles;
                                    $date = date( "Y-m-d H:i:s" );

                                    if ( $source_index === -1 ) {
                                        $article[SOURCE][] = $source;
                                        $article[SEARCH_TERMS][] = $search_terms;
                                        $article[RANK][] = $rank;
                                        $article[DATE][] = $date;
                                    } else {
                                        $article[RANK][ $source_index ] = $rank;
                                        $article[DATE][ $source_index ] = $date;
                                    }

                                    $articles[ $id ] = $article;
                                }
                            }
                        }
                    } while ( $processed_articles < $total );

                    if ( $total !== null ) {
                        finish_progress();
                    }
                }
            }
        }

        $fp = fopen( $output, "w" );

        if ( ! $fp ) {
            throw new Exception( "Cannot open output file" );
        }

        $total_articles_by_provider = [];

        foreach ( $articles as $article ) {
            fputcsv(
                $fp,
                array_map(
                    function( $value ) {
                        if ( is_array( $value ) ) {
                            $value = implode( ",", $value );
                        }

                        return $value;
                    },
                    $article
                )
            );

            $sources = array_unique( $article[SOURCE] );

            foreach ( $sources as $source ) {
                if ( ! isset( $total_articles_by_provider[ $source ] ) ) {
                    $total_articles_by_provider[ $source ] = 0;
                }

                $total_articles_by_provider[ $source ]++;
            }
        }

        message( "Articles added: $articles_added\nArticles updated: " . count( $articles_updated_map ) . "\nTotal articles: " . $old_articles + $articles_added . ( ! empty( $total_articles_by_provider ) ? " (" . implode( ",", array_map( function( $provider, $total ) { return "$provider: $total"; }, array_keys( $total_articles_by_provider ), $total_articles_by_provider ) ) . ")" : "" ) );

        fclose( $fp );
    }
} catch ( Exception $e ) {
    message( $e->getMessage() . " (use the --help argument)" );

    return 1;
}
