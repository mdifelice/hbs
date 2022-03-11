#!/usr/bin/php
<?php
define( "SCOPUS_API_KEY", "004355a38181067856f7154a74d3ba3f" );
define( "IEEE_API_KEY", "p2bvc6jvfj63v7w2m3rusmkr" );
define( "TITLE", 0 );
define( "SOURCE", 1 );
define( "AUTHORS", 2 );
define( "YEAR", 3 );
define( "DOI", 4 );
define( "SEARCH_TERMS", 5 );
define( "RANK", 6 );
define( "DATE", 7 );

function debug( $message ) {
    print( $message . PHP_EOL );
}

function get_article_index( $article ) {
    return md5( $article[TITLE] . implode( ",", $article[AUTHORS] ) . $article[YEAR] );
}

function parse_arg_settings( $settings ) {
    return array_merge(
        [
            "default" => null,
            "help" => null,
            "required" => false,
            "use_value" => false,
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

function parse_scopus_date( $placeholders ) {
    if ( $placeholders["start_year"] === $placeholders["end_year"] ) {
        $date = $placeholders["start_year"];
    } else {
        $date = $placeholders["start_year"] . "-" . $placeholders["end_year"];
    }

    return $date;
}

function parse_doi( $doi ) {
    $parsed_doi = "";

    if ( ! empty( $doi ) ) {
        $parsed_doi = "https://doi.org/" . $doi;
    }

    return $parsed_doi;
}

$apis = [
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
                    $article[DOI] = parse_doi( $raw_article->doi );

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
    "PubMed" => [
        "parse_articles" => function( $response ) {
            $articles = [];
            $summary_response = file_get_contents(
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

                                $article[TITLE] = $raw_article->title;
                                $article[AUTHORS] = array_map(
                                    function( $author ) {
                                        return $author->name;
                                    },
                                    $raw_article->authors
                                );
                                $article[YEAR] = date( "Y", strtotime( $raw_article->sortpubdate ) );
                                $article[DOI] = parse_doi( isset( $raw_article->doi ) ? $raw_article->doi : null );

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
    "Scopus" => [
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
                        $article[DOI] = parse_doi( $entry->{"prism:doi"} );

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
            "https://api.elsevier.com/content/search/scopus?apiKey=%s&httpAccept=application/json&count={count}&start={start}&query=KEY%%28{search_terms}%%29&date={callback:parse_scopus_date}",
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
    ],
    "help" => [
        "help" => "Prints help.",
    ],
    "verbose" => [
        "help" => "Increases debug level.",
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
                throw new Exception( "Unknown option \"" . $arg . "\"" );
            } else {
                $settings = parse_arg_settings( $possible_args[ $arg ] );

                if ( $settings["use_value"] ) {
                    if ( empty( $argv[ $i + 1 ] ) ) {
                        throw new Exception( "Missing value for option \"" . $arg . "\"" );
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
        debug(
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
                                        $settings["use_value"] ? "=<value>" : ""
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
                    throw new Exception( "Missing option \"". $arg . "\"" );
                } else {
                    $args[ $arg ] = $settings["default"];
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
        $articles_updated = 0;
        $search_terms = $args["search_terms"];
        $api = $args["api"];

        foreach ( $apis as $source => $settings ) {
            if ( ! $api || strtolower( $api ) === strtolower( $source ) ) {
                debug( "Calling API ". $source . " for search terms: \"" . $search_terms . "\"..." );

                $processed_articles = 0;
                $total = null;

                do {
                    $placeholders = array_merge(
                        $args,
                        [
                            "count" => 25,
                            "start" => $processed_articles,
                        ]
                    );

                    $request = preg_replace_callback(
                        "/{([^}]+)}/",
                        function( $matches ) use ( $placeholders ) {
                            $value = "";

                            if ( preg_match( "/^callback:(.+)$/", $matches[1], $sub_matches ) ) {
                                if ( function_exists( $sub_matches[1] ) ) {
                                    $value = $sub_matches[1]( $placeholders );
                                }
                            } elseif ( isset( $placeholders[ $matches[1] ] ) ) {
                                $value = $placeholders[ $matches[1] ];
                            }

                            return rawurlencode( $value );
                        },
                        $settings["request_mask"]
                    );

                    if ( $args["verbose"] ) {
                        debug( "URL: " . $request );
                    }

                    $response = file_get_contents( $request );

                    if ( $response ) {
                        $decoded_response = json_decode( $response );

                        if ( $decoded_response ) {
                            if ( $total === null ) {
                                $total = $settings["parse_total"]( $decoded_response );

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

                                    $articles_updated++;
                                }

                                $source_index = array_search( $source, $article[SOURCE] );

                                $rank = $processed_articles;
                                $date = date( "Y-m-d H:i:s" );

                                if ( $source_index === false || $article[SEARCH_TERMS][ $source_index ] !== $search_terms ) {
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

            foreach ( $article[SOURCE] as $source ) {
                if ( ! isset( $total_articles_by_provider[ $source ] ) ) {
                    $total_articles_by_provider[ $source ] = 0;
                }

                $total_articles_by_provider[ $source ]++;
            }
        }

        debug( "Articles added: $articles_added\nArticles updated: $articles_updated\nTotal articles: " . $old_articles + $articles_added . ( ! empty( $total_articles_by_provider ) ? " (" . implode( ",", array_map( function( $provider, $total ) { return "$provider: $total"; }, array_keys( $total_articles_by_provider ), $total_articles_by_provider ) ) . ")" : "" ) );

        fclose( $fp );
    }
} catch ( Exception $e ) {
    debug( $e->getMessage() );

    return 1;
}
