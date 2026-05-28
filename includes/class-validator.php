<?php
/**
 * Ligase Schema Validator
 *
 * Validates generated JSON-LD against Google's requirements.
 * Used by the admin validator tool and Gutenberg sidebar.
 *
 * @package Ligase
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Validator {

    /**
     * Contract-driven validation. Cheap delegation to the resolver so we have one
     * source of truth for "which fields are required" — instead of duplicating
     * the rules in per-type validate_* methods. Call this for types that have a
     * Ligase_Field_Contract entry; legacy validate_* methods stay for the rest.
     *
     * @return array{ eligible: bool, missing_required: string[], fields: array }
     */
    public function validate_via_contract( string $type, int $post_id ): array {
        if ( ! class_exists( 'Ligase_Field_Resolver' ) || ! class_exists( 'Ligase_Field_Contract' ) ) {
            return array( 'eligible' => false, 'missing_required' => array(), 'fields' => array() );
        }
        $resolver = new Ligase_Field_Resolver();
        $res      = $resolver->resolve( $type, $post_id );
        return array(
            'eligible'         => $res['eligible'],
            'missing_required' => $res['missing_required'],
            'fields'           => $res['status'],
        );
    }

    /**
     * Validate schema for a post.
     *
     * @param int $post_id Post ID.
     * @return array{valid: bool, errors: array, warnings: array, json: string}
     */
    public function validate_post( int $post_id ): array {
        $generator = new Ligase_Generator();
        $graph     = $generator->get_graph_for_post( $post_id );

        if ( empty( $graph ) ) {
            return [
                'valid'    => false,
                'errors'   => [ 'Brak wygenerowanej schema dla tego posta.' ],
                'warnings' => [],
                'json'     => '',
                'types'    => [],
            ];
        }

        $payload = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];

        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

        $errors   = [];
        $warnings = [];
        $types    = [];

        foreach ( $graph as $item ) {
            $type = $item['@type'] ?? 'Unknown';
            $types[] = $type;

            // Validate per type
            match ( $type ) {
                'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'LiveBlogPosting'
                                => $this->validate_article( $item, $errors, $warnings ),
                'Person'        => $this->validate_person( $item, $errors, $warnings ),
                'Organization'  => $this->validate_organization( $item, $errors, $warnings ),
                'Review'        => $this->validate_review( $item, $errors, $warnings ),
                'FAQPage'       => $this->validate_faq( $item, $errors, $warnings ),
                'HowTo'         => $this->validate_howto( $item, $errors, $warnings ),
                'VideoObject'   => $this->validate_video( $item, $errors, $warnings ),
                'Event'         => $this->validate_event( $item, $errors, $warnings ),
                'Course'        => $this->validate_course( $item, $errors, $warnings ),
                'SoftwareApplication' => $this->validate_software( $item, $errors, $warnings ),
                'ClaimReview'   => $this->validate_claim_review( $item, $errors, $warnings ),
                'QAPage'        => $this->validate_qa_page( $item, $errors, $warnings ),
                'LocalBusiness' => $this->validate_local_business( $item, $errors, $warnings ),
                'Service'       => $this->validate_service( $item, $errors, $warnings ),
                'Product'       => $this->validate_product( $item, $errors, $warnings ),
                'ProductGroup'  => $this->validate_product_group( $item, $errors, $warnings ),
                'Recipe'        => $this->validate_recipe( $item, $errors, $warnings ),
                'JobPosting'    => $this->validate_jobposting( $item, $errors, $warnings ),
                'DiscussionForumPosting' => $this->validate_forum_posting( $item, $errors, $warnings ),
                default         => null,
            };
        }

        // Validate JSON structure
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $errors[] = 'Blad kodowania JSON: ' . json_last_error_msg();
        }

        // Check @graph has @id references
        $has_ids = 0;
        foreach ( $graph as $item ) {
            if ( ! empty( $item['@id'] ) ) {
                $has_ids++;
            }
        }
        if ( $has_ids < 2 ) {
            $warnings[] = 'Mniej niz 2 encje maja @id — entity graph moze byc niekompletny.';
        }

        return [
            'valid'    => empty( $errors ),
            'errors'   => $errors,
            'warnings' => $warnings,
            'json'     => $json ?: '',
            'types'    => array_unique( $types ),
        ];
    }

    private function validate_article( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['headline'] ) ) {
            $errors[] = 'Article: brak headline (wymagane).';
        } elseif ( mb_strlen( $s['headline'] ) > 110 ) {
            $warnings[] = 'Article: headline dluzszy niz 110 znakow (' . mb_strlen( $s['headline'] ) . ').';
        }

        if ( empty( $s['datePublished'] ) ) {
            $errors[] = 'Article: brak datePublished (wymagane).';
        }

        if ( empty( $s['author'] ) ) {
            $errors[] = 'Article: brak author (wymagane).';
        }

        if ( empty( $s['image'] ) ) {
            $errors[] = 'Article: brak image (wymagane dla rich results).';
        }

        $publisher = $s['publisher'] ?? [];
        if ( empty( $publisher ) ) {
            $warnings[] = 'Article: brak publisher.';
        } elseif ( empty( $publisher['name'] ) && empty( $publisher['@id'] ) ) {
            $warnings[] = 'Article: publisher nie ma name ani @id.';
        }

        if ( empty( $s['dateModified'] ) ) {
            $warnings[] = 'Article: brak dateModified.';
        }

        if ( empty( $s['description'] ) ) {
            $warnings[] = 'Article: brak description.';
        }

        // speakable is intentionally optional — only warn if CSS selectors are configured globally
        $opts = (array) get_option( 'ligase_options', array() );
        if ( empty( $s['speakable'] ) && ! empty( $opts['speakable_selectors'] ) ) {
            $warnings[] = 'Article: brak speakable mimo skonfigurowanych selektorów CSS.';
        }
    }

    private function validate_person( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'Person: brak name.';
        }

        // Build edit-profile URL for this author if we can identify them
        $author_url = '';
        if ( ! empty( $s['@id'] ) ) {
            // @id format: https://site.com/#author-{id}
            preg_match( '/#author-(\d+)$/', $s['@id'], $m );
            if ( ! empty( $m[1] ) ) {
                $author_url = admin_url( 'user-edit.php?user_id=' . $m[1] );
            }
        }
        if ( ! $author_url ) {
            $author_url = admin_url( 'profile.php' );
        }

        $fix_link = ' → <a href="' . esc_url( $author_url ) . '">Edytuj profil autora</a>';

        if ( empty( $s['sameAs'] ) ) {
            $warnings[] = 'Person: brak sameAs (LinkedIn, Wikidata) — ważne dla E-E-A-T. Uzupełnij raz, działa dla wszystkich postów.' . $fix_link;
        }
        if ( empty( $s['knowsAbout'] ) ) {
            $warnings[] = 'Person: brak knowsAbout (ekspertyza) — ważne dla AI search. Uzupełnij raz, działa dla wszystkich postów.' . $fix_link;
        }
        if ( empty( $s['jobTitle'] ) ) {
            $warnings[] = 'Person: brak jobTitle (stanowisko). Uzupełnij raz, działa dla wszystkich postów.' . $fix_link;
        }
    }

    private function validate_organization( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'Organization: brak name.';
        }

        if ( empty( $s['logo'] ) && empty( $s['@id'] ) ) {
            $warnings[] = 'Organization: brak logo (wymagane dla Article rich results) — dodaj w Ustawienia → Organizacja.';
        } elseif ( ! empty( $s['@id'] ) && empty( $s['logo'] ) ) {
            // @id reference — logo is on the full Organization object, not here
        } elseif ( empty( $s['logo'] ) ) {
            $org_link = ' → <a href="' . esc_url( admin_url( 'admin.php?page=ligase-ustawienia&tab=organization' ) ) . '">Ligase → Ustawienia → Organizacja</a>';
            $warnings[] = 'Organization: brak logo — wymagane dla Article rich results.' . $org_link;
        }

        if ( empty( $s['sameAs'] ) ) {
            $settings_link = ' → <a href="' . esc_url( admin_url( 'admin.php?page=ligase-ustawienia&tab=social' ) ) . '">Ligase → Ustawienia → Social & Entity</a>';
            $warnings[] = 'Organization: brak sameAs (Wikidata, LinkedIn). Uzupełnij raz dla całej witryny.' . $settings_link;
        }
    }

    private function validate_review( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['reviewRating'] ) ) {
            $errors[] = 'Review: brak reviewRating.';
        }

        if ( empty( $s['itemReviewed'] ) ) {
            $warnings[] = 'Review: brak itemReviewed — Google moze odrzucic rich result.';
        }

        if ( empty( $s['reviewBody'] ) ) {
            $warnings[] = 'Review: brak reviewBody.';
        }
    }

    private function validate_faq( array $s, array &$errors, array &$warnings ): void {
        $entities = $s['mainEntity'] ?? [];
        if ( count( $entities ) < 2 ) {
            $errors[] = 'FAQPage: minimum 2 pytania (znaleziono ' . count( $entities ) . ').';
        }

        $warnings[] = 'FAQPage: rich results ograniczone do gov/health od 2024. Schema nadal dziala dla AI.';
    }

    private function validate_video( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'VideoObject: brak name.';
        }

        if ( empty( $s['thumbnailUrl'] ) ) {
            $errors[] = 'VideoObject: brak thumbnailUrl (wymagane).';
        }

        if ( empty( $s['uploadDate'] ) ) {
            $errors[] = 'VideoObject: brak uploadDate (wymagane).';
        }

        if ( empty( $s['duration'] ) ) {
            $warnings[] = 'VideoObject: brak duration.';
        }
    }

    private function validate_event( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'Event: brak name (wymagane).';
        }

        if ( empty( $s['startDate'] ) ) {
            $errors[] = 'Event: brak startDate (wymagane).';
        }

        if ( empty( $s['location'] ) ) {
            $warnings[] = 'Event: brak location.';
        }
    }

    private function validate_howto( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'HowTo: brak name (wymagane).';
        }

        $steps = $s['step'] ?? [];
        if ( empty( $steps ) || count( $steps ) < 1 ) {
            $errors[] = 'HowTo: brak step — minimum 1 krok wymagany przez Google.';
        } else {
            foreach ( $steps as $i => $step ) {
                if ( empty( $step['text'] ) && empty( $step['name'] ) ) {
                    $errors[] = 'HowTo: krok #' . ( $i + 1 ) . ' nie ma name ani text.';
                }
            }
        }

        if ( empty( $s['totalTime'] ) ) {
            $warnings[] = 'HowTo: brak totalTime (ISO 8601, np. PT30M) — zalecane.';
        }

        if ( empty( $s['image'] ) ) {
            $warnings[] = 'HowTo: brak image — zalecane dla rich results.';
        }
    }

    private function validate_course( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'Course: brak name (wymagane).';
        }

        if ( empty( $s['description'] ) ) {
            $errors[] = 'Course: brak description (wymagane).';
        }

        if ( empty( $s['provider'] ) ) {
            $warnings[] = 'Course: brak provider — Google zaleca wskazanie organizacji.';
        }

        if ( empty( $s['hasCourseInstance'] ) ) {
            $warnings[] = 'Course: brak hasCourseInstance — wymagane do wyświetlenia w Google Search.';
        } else {
            foreach ( (array) $s['hasCourseInstance'] as $instance ) {
                if ( empty( $instance['courseMode'] ) ) {
                    $warnings[] = 'Course > CourseInstance: brak courseMode (online/onsite/blended).';
                }
            }
        }
    }

    private function validate_product( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'Product: brak name (wymagane).';
        }

        $has_review = ! empty( $s['review'] );
        $has_rating = ! empty( $s['aggregateRating'] );
        $has_offer  = ! empty( $s['offers'] );

        if ( ! $has_review && ! $has_rating && ! $has_offer ) {
            $errors[] = 'Product: wymagane co najmniej JEDNO z review / aggregateRating / offers.';
        }

        if ( empty( $s['image'] ) ) {
            $warnings[] = 'Product: brak image — Google silnie rekomenduje przynajmniej jedno zdjęcie.';
        }

        if ( empty( $s['brand'] ) && empty( $s['gtin'] ) && empty( $s['mpn'] ) && empty( $s['sku'] ) ) {
            $warnings[] = 'Product: brak brand/gtin/mpn/sku — przynajmniej jeden identyfikator wzmacnia merchant listing.';
        }

        if ( $has_offer ) {
            $offer = $s['offers'];
            if ( ! isset( $offer['price'] ) || $offer['price'] === '' ) {
                $errors[] = 'Product > offers: brak price (wymagane).';
            }
            if ( empty( $offer['priceCurrency'] ) ) {
                $warnings[] = 'Product > offers: brak priceCurrency (ISO 4217, np. PLN).';
            }
            if ( ! empty( $offer['priceValidUntil'] ) ) {
                $ts = strtotime( (string) $offer['priceValidUntil'] );
                if ( $ts && $ts < time() ) {
                    $warnings[] = 'Product > offers: priceValidUntil w przeszłości — Google może wyłączyć snippet.';
                }
            }
            if ( empty( $offer['availability'] ) ) {
                $warnings[] = 'Product > offers: brak availability (np. https://schema.org/InStock).';
            }
            // Merchant listing wymaga return policy + shipping od marca 2025.
            if ( empty( $offer['hasMerchantReturnPolicy'] ) ) {
                $warnings[] = 'Product > offers: brak hasMerchantReturnPolicy — wymagane dla merchant listing (od marca 2025).';
            } else {
                $rp = $offer['hasMerchantReturnPolicy'];
                if ( empty( $rp['returnPolicyCountry'] ) ) {
                    $errors[] = 'Product > hasMerchantReturnPolicy: brak returnPolicyCountry (wymagane od marca 2025).';
                }
            }
            if ( empty( $offer['shippingDetails'] ) ) {
                $warnings[] = 'Product > offers: brak shippingDetails — wymagane dla merchant listing.';
            }
        }

        if ( ! empty( $s['aggregateRating'] ) ) {
            $r = $s['aggregateRating'];
            if ( empty( $r['ratingValue'] ) || empty( $r['reviewCount'] ) ) {
                $warnings[] = 'Product > aggregateRating: brak ratingValue lub reviewCount.';
            }
        }
    }

    private function validate_product_group( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'ProductGroup: brak name (wymagane).';
        }
        if ( empty( $s['hasVariant'] ) || ! is_array( $s['hasVariant'] ) ) {
            $errors[] = 'ProductGroup: brak hasVariant (lista wariantów) lub niepoprawny typ.';
            return;
        }
        if ( count( $s['hasVariant'] ) < 2 ) {
            $warnings[] = 'ProductGroup: <2 warianty — rozważ pojedynczy Product zamiast Group.';
        }
        if ( empty( $s['variesBy'] ) || ! is_array( $s['variesBy'] ) ) {
            $warnings[] = 'ProductGroup: brak variesBy — Google używa tego do identyfikacji wymiarów wariantu (rozmiar/kolor).';
        }
        $skus = [];
        foreach ( $s['hasVariant'] as $i => $variant ) {
            if ( ! is_array( $variant ) ) {
                $errors[] = "ProductGroup > hasVariant[{$i}]: niepoprawny typ.";
                continue;
            }
            if ( empty( $variant['sku'] ) ) {
                $errors[] = "ProductGroup > hasVariant[{$i}]: brak sku (wymagane dla wariantu).";
            } elseif ( in_array( $variant['sku'], $skus, true ) ) {
                $errors[] = "ProductGroup > hasVariant[{$i}]: duplikat sku '{$variant['sku']}'.";
            } else {
                $skus[] = $variant['sku'];
            }
            if ( empty( $variant['offers'] ) ) {
                $warnings[] = "ProductGroup > hasVariant[{$i}]: brak offers — wariant bez Offer nie pojawi się w merchant listing.";
            }
        }
    }

    private function validate_recipe( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'Recipe: brak name (wymagane).';
        }
        if ( empty( $s['image'] ) ) {
            $errors[] = 'Recipe: brak image (wymagane przez Google).';
        }
        if ( empty( $s['recipeIngredient'] ) ) {
            $warnings[] = 'Recipe: brak recipeIngredient — rich result wymaga listy składników.';
        }
        if ( empty( $s['recipeInstructions'] ) ) {
            $warnings[] = 'Recipe: brak recipeInstructions — rich result wymaga kroków przygotowania.';
        }
        $duration_re = '/^P(?:\d+[YMWD])*(?:T(?:\d+[HMS])*)?$/';
        foreach ( [ 'prepTime', 'cookTime', 'totalTime' ] as $key ) {
            if ( ! empty( $s[ $key ] ) && ! preg_match( $duration_re, (string) $s[ $key ] ) ) {
                $warnings[] = "Recipe: $key powinno być ISO 8601 duration (np. PT15M).";
            }
        }
    }

    private function validate_jobposting( array $s, array &$errors, array &$warnings ): void {
        $required = array( 'title', 'description', 'datePosted', 'validThrough', 'hiringOrganization' );
        foreach ( $required as $key ) {
            if ( empty( $s[ $key ] ) ) {
                $errors[] = "JobPosting: brak $key (wymagane).";
            }
        }
        if ( empty( $s['jobLocation'] ) && empty( $s['jobLocationType'] ) ) {
            $errors[] = 'JobPosting: wymagane jobLocation (z addressLocality + addressCountry) lub jobLocationType=TELECOMMUTE dla pracy zdalnej.';
        }
        if ( ! empty( $s['validThrough'] ) ) {
            $ts = strtotime( (string) $s['validThrough'] );
            if ( $ts && $ts < time() ) {
                $warnings[] = 'JobPosting: validThrough w przeszłości — Google usuwa wygasłe oferty z Google Jobs.';
            }
        }
        if ( ! empty( $s['hiringOrganization']['name'] ) ) {
            // OK
        } elseif ( ! empty( $s['hiringOrganization'] ) ) {
            $warnings[] = 'JobPosting > hiringOrganization: brak name.';
        }
        if ( empty( $s['employmentType'] ) ) {
            $warnings[] = 'JobPosting: brak employmentType (FULL_TIME / PART_TIME / CONTRACTOR / TEMPORARY / INTERN / VOLUNTEER).';
        }
        if ( empty( $s['baseSalary'] ) ) {
            $warnings[] = 'JobPosting: brak baseSalary — oferty z widełkami mają dużo lepszy CTR.';
        }
    }

    private function validate_forum_posting( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['headline'] ) ) {
            $errors[] = 'DiscussionForumPosting: brak headline (wymagane).';
        }
        if ( empty( $s['text'] ) && empty( $s['articleBody'] ) ) {
            $errors[] = 'DiscussionForumPosting: brak text/articleBody (treść posta wymagana).';
        }
        if ( empty( $s['datePublished'] ) ) {
            $errors[] = 'DiscussionForumPosting: brak datePublished.';
        }
        if ( empty( $s['author'] ) ) {
            $errors[] = 'DiscussionForumPosting: brak author.';
        }
        if ( empty( $s['interactionStatistic'] ) ) {
            $warnings[] = 'DiscussionForumPosting: brak interactionStatistic — liczniki komentarzy/polubień wzmacniają sygnał discussion.';
        }
    }

    private function validate_software( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'SoftwareApplication: brak name (wymagane).';
        }

        if ( empty( $s['applicationCategory'] ) ) {
            $warnings[] = 'SoftwareApplication: brak applicationCategory (np. WebApplication, MobileApplication).';
        }

        if ( empty( $s['operatingSystem'] ) ) {
            $warnings[] = 'SoftwareApplication: brak operatingSystem.';
        }

        if ( empty( $s['offers'] ) ) {
            $warnings[] = 'SoftwareApplication: brak offers — rating w SERP wymaga ceny lub "Free".';
        }

        if ( empty( $s['aggregateRating'] ) ) {
            $warnings[] = 'SoftwareApplication: brak aggregateRating — aktywny rich result w Google.';
        }
    }

    private function validate_claim_review( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['claimReviewed'] ) ) {
            $errors[] = 'ClaimReview: brak claimReviewed (wymagane — treść sprawdzanego twierdzenia).';
        }

        if ( empty( $s['reviewRating'] ) ) {
            $errors[] = 'ClaimReview: brak reviewRating (wymagane).';
        } else {
            $rating = $s['reviewRating'];
            if ( empty( $rating['ratingValue'] ) ) {
                $errors[] = 'ClaimReview > reviewRating: brak ratingValue.';
            }
            if ( empty( $rating['bestRating'] ) || empty( $rating['worstRating'] ) ) {
                $warnings[] = 'ClaimReview > reviewRating: brak bestRating / worstRating.';
            }
            if ( empty( $rating['alternateName'] ) ) {
                $warnings[] = 'ClaimReview > reviewRating: brak alternateName (etykieta werdyktu, np. "Fałsz").';
            }
        }

        if ( empty( $s['author'] ) ) {
            $warnings[] = 'ClaimReview: brak author — zalecane dla E-E-A-T.';
        }

        if ( empty( $s['url'] ) ) {
            $warnings[] = 'ClaimReview: brak url — zalecane.';
        }
    }

    private function validate_qa_page( array $s, array &$errors, array &$warnings ): void {
        $entities = $s['mainEntity'] ?? [];
        if ( empty( $entities ) ) {
            $errors[] = 'QAPage: brak mainEntity — wymagane pytanie z odpowiedzią.';
            return;
        }

        $question = is_array( $entities ) && isset( $entities[0] ) ? $entities[0] : $entities;

        if ( empty( $question['name'] ) ) {
            $errors[] = 'QAPage > Question: brak name (treść pytania).';
        }

        $answers = $question['acceptedAnswer'] ?? $question['suggestedAnswer'] ?? [];
        if ( empty( $answers ) ) {
            $errors[] = 'QAPage > Question: brak acceptedAnswer ani suggestedAnswer.';
        } else {
            $answer = is_array( $answers ) && isset( $answers[0] ) ? $answers[0] : $answers;
            if ( empty( $answer['text'] ) ) {
                $errors[] = 'QAPage > Answer: brak text w odpowiedzi.';
            }
        }

        $warnings[] = 'QAPage: rich results aktywne — strona powinna odpowiadać na jedno konkretne pytanie.';
    }

    private function validate_local_business( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'LocalBusiness: brak name (wymagane).';
        }

        if ( empty( $s['address'] ) ) {
            $errors[] = 'LocalBusiness: brak address (wymagane dla rich results).';
        } else {
            $addr = $s['address'];
            if ( empty( $addr['streetAddress'] ) ) {
                $warnings[] = 'LocalBusiness > address: brak streetAddress.';
            }
            if ( empty( $addr['addressLocality'] ) ) {
                $warnings[] = 'LocalBusiness > address: brak addressLocality (miasto).';
            }
            if ( empty( $addr['addressCountry'] ) ) {
                $warnings[] = 'LocalBusiness > address: brak addressCountry.';
            }
        }

        if ( empty( $s['telephone'] ) ) {
            $warnings[] = 'LocalBusiness: brak telephone — ważny sygnał lokalny.';
        }

        if ( empty( $s['openingHoursSpecification'] ) ) {
            $warnings[] = 'LocalBusiness: brak openingHoursSpecification — zalecane.';
        }

        if ( empty( $s['geo'] ) ) {
            $warnings[] = 'LocalBusiness: brak geo (GeoCoordinates) — ważne dla AI i Google Maps.';
        }

        if ( empty( $s['image'] ) ) {
            $warnings[] = 'LocalBusiness: brak image — zalecane.';
        }
    }

    private function validate_service( array $s, array &$errors, array &$warnings ): void {
        if ( empty( $s['name'] ) ) {
            $errors[] = 'Service: brak name (wymagane).';
        }

        if ( empty( $s['provider'] ) ) {
            $warnings[] = 'Service: brak provider — połącz usługę z Organization przez @id.';
        }

        if ( empty( $s['description'] ) ) {
            $warnings[] = 'Service: brak description.';
        }

        if ( empty( $s['serviceType'] ) ) {
            $warnings[] = 'Service: brak serviceType — pomaga AI kategoryzować usługę.';
        }
    }
}
