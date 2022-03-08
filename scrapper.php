#!/usr/bin/php
<?php
define( 'SCOPUS_API_KEY', '004355a38181067856f7154a74d3ba3f' );
define( 'IEEE_API_KEY', 'p2bvc6jvfj63v7w2m3rusmkr' );

function debug( $message ) {
    printf( $message . PHP_EOL );
}

function get_article_index( $article ) {
    return md5( $article[TITLE] . implode( ',', $article[AUTHORS] ) . $article[YEAR] );
}

define( 'TITLE', 0 );
define( 'SOURCE', 1 );
define( 'AUTHORS', 2 );
define( 'YEAR', 3 );
define( 'DOI', 4 );
define( 'SEARCH_TERMS', 5 );
define( 'RANK', 6 );
define( 'DATE', 7 );

$apis = array(
    'IEEEXplore' => array(
        'parse_articles' => function( $response ) {
            $articles = [];

            if ( ! empty( $response->articles ) ) {
                foreach ( $response->articles as $raw_article ) {
                    $doi = '';

                    if ( ! empty( $raw_article->doi ) ) {
                        $doi = 'https://doi.org/' . $raw_article->doi;
                    }

                    $article = [];

                    $article[TITLE] = $raw_article->title;
                    $article[AUTHORS] = array_map(
                        function( $author ) {
                            return $author->full_name;
                        },
                        $raw_article->authors->authors
                    );
                    $article[YEAR] = $raw_article->publication_year;
                    $article[DOI] = $doi;

                    $articles[] = $article;
                }
            }

            return $articles;
        },
        'parse_total' => function( $response ) {
            return $response->total_records;
        },
        'request_mask' => sprintf(
            'http://ieeexploreapi.ieee.org/api/v1/search/articles?apikey=%s&format=json&max_records={count}&start_record={start}&index_terms={search_terms}',
            rawurlencode( IEEE_API_KEY )
        ),
    ),
    'PubMed' => array(
        'request_mask' => 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retstart={start}&retmax={count}&retmode=json&term={search_terms}',
        'parse_articles' => function( $response ) {
            $articles = array();
            $summary_response = file_get_contents(
                sprintf(
                    'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&id=%s&retmode=json',
                    rawurlencode(
                        implode(
                            ',',
                            $response->esearchresult->idlist
                        )
                    )
                )
            );

            if ( $summary_response ) {
                $summary_response_decoded = json_decode( $summary_response );

                if ( $summary_response_decoded ) {
                    $result = $summary_response_decoded->result;

                    foreach ( $result->uids as $id ) {
                        if ( isset( $result[ $id ] ) ) {
                            $raw_article = $result[ $id ];

                            $doi = '';

                            if ( ! empty( $raw_article->doi ) ) {
                                $doi = 'https://doi.org/' . $raw_article->doi;
                            }

                            $article = [];

                            $article[TITLE] = $raw_article->title;
                            $article[AUTHORS] = array_map(
                                function( $author ) {
                                    return $author->name;
                                },
                                $raw_article->authors
                            );
                            $article[YEAR] = date( 'Y', strtotime( $raw_article->sortpubdate ) );
                            $article[DOI] = $doi;

                            $articles[] = $article;
                        }
                    }
                }
            }

            return $articles;
        },
        'parse_total' => function( $response ) {
            return $response->esearchresult->count;
        },
    ),
    'Scopus' => array(
        'parse_articles' => function( $response ) {
            $articles = [];

            if ( ! empty( $response->{'search-results'}->entry ) ) {
                foreach ( $response->{'search-results'}->entry as $index => $entry ) {
                    $article = [];

                    $authors = array();

                    if ( isset( $entry->{'dc:creator'} ) ) {
                        $authors[] = $entry->{'dc:creator'};
                    }

                    $doi = '';

                    if ( ! empty( $entry->{'prism:doi'} ) ) {
                        $doi = 'https://doi.org/' . $entry->{'prism:doi'};
                    }

                    $article[TITLE] = $entry->{'dc:title'};
                    $article[AUTHORS] = $authors;
                    $article[YEAR] = date( 'Y', strtotime( $entry->{'prism:coverDate'} ) );
                    $article[DOI] = $doi;

                    $articles[] = $article;
                }
            }

            return $articles;
        },
        'parse_total' => function( $response ) {
            return $response->{'search-results'}->{'opensearch:totalResults'};
        },
        'request_mask' => sprintf(
            'https://api.elsevier.com/content/search/scopus?apiKey=%s&httpAccept=application/json&count={count}&start={start}&query=KEY%%28{search_terms}%%29',
            rawurlencode( SCOPUS_API_KEY )
        ),
    ),
);

try {
    $search_terms = '';
    $articles = [];
    $total = 0;
    $output = null;
    $api = null;

    for ( $i = 0; $i < count( $argv ); $i++ ) {
        if (
            preg_match( '/^--(.+)$/', $argv[ $i ], $matches )
            && isset( $argv[ $i + 1 ] )
        ) {
            $i++;

            switch ( $matches[1] ) {
            case 'output':
                $output = $argv[ $i ];
                break;
            case 'search_terms':
                $search_terms = $argv[ $i ];
                break;
            case 'api':
                $api = $argv[ $i ];
                break;
            default:
                throw new Exception( 'Unknown option "' . $matches[1] . '"' );
            }
        }
    }

    if ( empty( $search_terms ) ) {
        throw new Exception( 'You must specify search terms' );
    }

    if ( empty( $output ) ) {
        throw new Exception( 'Missing output file' );
    }

    if ( file_exists( $output ) ) {
        $fp = fopen( $output, 'r' );

        if ( $fp ) {
            while ( $article = fgetcsv( $fp ) ) {
                $article[SOURCE] = explode( ',', $article[SOURCE] );
                $article[AUTHORS] = explode( ',', $article[AUTHORS] );
                $article[SEARCH_TERMS] = explode( ',', $article[SEARCH_TERMS] );
                $article[RANK] = explode( ',', $article[RANK] );
                $article[DATE] = explode( ',', $article[DATE] );

                $id = get_article_index( $article );

                $articles[ $id ] = $article;
            }

            fclose( $fp );
        }
    }

    $articles_added = 0;
    $articles_updated = 0;

    foreach ( $apis as $source => $settings ) {
        if ( ! $api || strtolower( $api ) === strtolower( $source ) ) {
            debug( 'Calling API '. $source . ' for search terms: "' . $search_terms . '"...' );

            $processed_articles = 0;
            $total = 0;

            do {
                $placeholders = array(
                    'count' => 25,
                    'search_terms' => $search_terms,
                    'start' => $processed_articles,
                );

                $request = preg_replace_callback(
                    '/{([^}]+)}/',
                    function( $matches ) use ( $placeholders ) {
                        if ( isset( $placeholders[ $matches[1] ] ) ) {
                            $value = $placeholders[ $matches[1] ];
                        } else {
                            $value = '';
                        }

                        return rawurlencode( $value );
                    },
                    $settings['request_mask']
                );

                $response = file_get_contents( $request );

                if ( $response ) {
                    $decoded_response = json_decode( $response );

                    if ( $decoded_response ) {
                        $total = $settings['parse_total']( $decoded_response );
                        $page_articles = $settings['parse_articles']( $decoded_response );

                        foreach ( $page_articles as $page_article ) {
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
                            $date = date( 'Y-m-d H:i:s' );

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

                debug( 'Received ' . $processed_articles . ' of ' . $total );
            } while ( $processed_articles < $total );
        }
    }

    $fp = fopen( $output, 'w' );

    if ( ! $fp ) {
        throw new Exception( 'Cannot open output file' );
    }

    foreach ( $articles as $article ) {
        fputcsv(
            $fp,
            array_map(
                function( $value ) {
                    if ( is_array( $value ) ) {
                        $value = implode( ',', $value );
                    }

                    return $value;
                },
                $article
            )
        );
    }

    debug( "Articles added: $articles_added\nArticles updated: $articles_updated" );

    fclose( $fp );
} catch ( Exception $e ) {
    debug( $e->getMessage() );

    return 1;
}
