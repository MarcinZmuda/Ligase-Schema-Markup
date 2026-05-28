<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Entity_Pipeline {

    /**
     * @param string $mode 'standard' | 'deep' | 'wikidata'
     */
    public function analyze( int $post_id, string $mode = 'standard' ): array {
        $results = [];

        // Level 1 — always, ~0ms
        $results['native'] = ( new Ligase_Entity_Extractor_Native() )->extract( $post_id );

        // Level 2 — always, ~5ms
        $results['structural'] = ( new Ligase_Entity_Extractor_Structure() )->extract( $post_id );

        // Level 3a — local regex NER (fast, ~20ms), in deep/wikidata mode
        if ( in_array( $mode, [ 'deep', 'wikidata' ], true ) && class_exists( 'Ligase_Entity_Extractor_NER' ) ) {
            $results['ner'] = ( new Ligase_Entity_Extractor_NER() )->extract_from_post( $post_id );
        }

        // Level 3b — async LLM NER results from previous cron runs (higher quality, merged
        // into the local regex output with LLM taking precedence).
        $ner_api = get_post_meta( $post_id, '_ligase_ner_api_results', true );
        if ( is_array( $ner_api ) && ! empty( $ner_api ) ) {
            $results['ner'] = $this->merge_ner( $results['ner'] ?? [], $ner_api );
        }

        // Level 4 — async results from previous Wikidata lookups
        $results['wikidata_suggestions'] = get_post_meta( $post_id, '_ligase_wikidata_suggestions', true ) ?: [];

        return $this->map_to_schema_hints( $results, $post_id );
    }

    /**
     * Merge local-regex NER results with LLM NER results. LLM entries win on conflict
     * (higher confidence), but local entries are kept if the LLM didn't surface them.
     */
    private function merge_ner( array $local, array $api ): array {
        $merged = $local;
        foreach ( [ 'persons', 'organizations', 'products', 'locations' ] as $bucket ) {
            $by_name = [];
            foreach ( ( $merged[ $bucket ] ?? [] ) as $entity ) {
                if ( ! empty( $entity['name'] ) ) {
                    $by_name[ mb_strtolower( $entity['name'] ) ] = $entity;
                }
            }
            foreach ( ( $api[ $bucket ] ?? [] ) as $entity ) {
                if ( ! empty( $entity['name'] ) ) {
                    $entity['source'] = 'llm';
                    $by_name[ mb_strtolower( $entity['name'] ) ] = $entity;
                }
            }
            if ( ! empty( $by_name ) ) {
                $merged[ $bucket ] = array_values( $by_name );
            }
        }
        return $merged;
    }

    private function map_to_schema_hints( array $entities, int $post_id ): array {
        $hints = [];

        // keywords from tags
        if ( ! empty( $entities['native']['keywords'] ) ) {
            $hints['keywords'] = array_column( $entities['native']['keywords'], 'name' );
        }

        // articleSection from first category
        if ( ! empty( $entities['native']['topics'][0] ) ) {
            $hints['articleSection'] = $entities['native']['topics'][0]['name'];
        }

        // about — Wikipedia links in content (ready sameAs)
        if ( ! empty( $entities['structural']['wiki_mentions'] ) ) {
            $hints['about'] = array_map( fn( $l ) => [
                '@type'  => 'Thing',
                'name'   => $l['text'],
                'sameAs' => $l['url'],
            ], $entities['structural']['wiki_mentions'] );
        }

        // VideoObject suggestion
        if ( ! empty( $entities['structural']['youtube_ids'][0] ) ) {
            $hints['_suggest_video'] = $entities['structural']['youtube_ids'][0];
        }

        // FAQ suggestion
        if ( ! empty( $entities['structural']['blocks']['faq'] ) ) {
            $hints['_suggest_faq'] = true;
        }

        // HowTo suggestion
        if ( ! empty( $entities['structural']['blocks']['howto'] ) ) {
            $hints['_suggest_howto'] = true;
        }

        // NER entities
        if ( ! empty( $entities['ner'] ) ) {
            $hints['_ner_entities'] = $entities['ner'];
        }

        // Wikidata suggestions + auto-apply
        if ( ! empty( $entities['wikidata_suggestions'] ) ) {
            $hints['_wikidata'] = $entities['wikidata_suggestions'];

            // Auto-apply criteria (all required) — count === 1 alone is unreliable because
            // wbsearchentities frequently returns 1 ambiguous match for rare strings, which
            // produced wrong-entity sameAs links. Now we additionally require:
            //   - Wikidata label exactly matches the entity name (case-insensitive), OR
            //   - The entity was confirmed by the LLM NER (`source === 'llm'`)
            $llm_confirmed = [];
            foreach ( [ 'persons', 'organizations', 'products', 'locations' ] as $bucket ) {
                foreach ( ( $entities['ner'][ $bucket ] ?? [] ) as $entity ) {
                    if ( ( $entity['source'] ?? '' ) === 'llm' && ! empty( $entity['name'] ) ) {
                        $llm_confirmed[ mb_strtolower( $entity['name'] ) ] = true;
                    }
                }
            }

            $auto_sameas = [];
            foreach ( $entities['wikidata_suggestions'] as $name => $matches ) {
                if ( ! is_array( $matches ) || count( $matches ) !== 1 ) {
                    continue;
                }
                $match            = $matches[0];
                $label_match      = isset( $match['label'] ) && mb_strtolower( (string) $match['label'] ) === mb_strtolower( (string) $name );
                $llm_match        = isset( $llm_confirmed[ mb_strtolower( (string) $name ) ] );
                if ( ! $label_match && ! $llm_match ) {
                    continue;
                }
                $auto_sameas[] = [
                    'name'         => $name,
                    'wikidata_id'  => $match['id'],
                    'wikidata_url' => $match['url'],
                    'label'        => $match['label'],
                    'confidence'   => $label_match && $llm_match ? 'high' : 'medium',
                ];
            }
            if ( ! empty( $auto_sameas ) ) {
                $hints['_auto_sameas'] = $auto_sameas;
            }
        }

        // Schedule Wikidata lookup for NER entities that don't have matches yet
        if ( ! empty( $entities['ner'] ) && class_exists( 'Ligase_Wikidata_Lookup' ) ) {
            $names_to_lookup = [];
            $existing = array_keys( $entities['wikidata_suggestions'] ?: [] );
            foreach ( [ 'persons', 'organizations', 'products' ] as $type ) {
                if ( ! empty( $entities['ner'][ $type ] ) ) {
                    foreach ( $entities['ner'][ $type ] as $entity ) {
                        if ( ! in_array( $entity['name'], $existing, true ) && $entity['frequency'] >= 2 ) {
                            $names_to_lookup[] = $entity['name'];
                        }
                    }
                }
            }
            if ( ! empty( $names_to_lookup ) ) {
                ( new Ligase_Wikidata_Lookup() )->schedule( $post_id, array_unique( $names_to_lookup ) );
                $hints['_wikidata_scheduled'] = count( $names_to_lookup );
            }
        }

        return $hints;
    }
}
