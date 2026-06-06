<?php
/**
 * Ligase - Schema popularity stats from Google's open-web crawl
 *
 * Static dataset derived from `schemaorg/schemaorg` repo:
 *   data/public_stats/google/2026_05.csv
 *
 * Source: Google publishes domain-bucket counts for every schema.org Itemtype
 * and Predicate the search-engine crawler observed in the public web. Buckets
 * are coarse but useful as a signal: a type used by 10M+ domains has
 * production-grade tooling, doc coverage, and Search Console rich-result
 * integration; a type in <1K is experimental or extremely niche.
 *
 * We use these buckets in two places:
 *   1. The post-edit meta-box: badge next to each schema-enable checkbox
 *      shows users whether a type is widely adopted (green), niche (yellow),
 *      or experimental (red). Helps decide whether enabling makes sense.
 *   2. The Audytor settings page: when recommending a schema type for a post,
 *      we prefer popular types as the "safe default" unless content strongly
 *      suggests otherwise.
 *
 * Data refresh cadence: schemaorg drops a new CSV roughly every 6 months.
 * Update by running `gh api repos/schemaorg/schemaorg/contents/data/public_stats/google`
 * and pulling the latest CSV; regenerate this map by Itemtype rows.
 *
 * @package Ligase
 * @since   2.4.20
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Popularity_Stats {

    /**
     * Bucket -> tier code used by the UI. Lower tier = wider adoption.
     */
    const TIER_VERY_HIGH = 1; // 10M+
    const TIER_HIGH      = 2; // 1M-10M
    const TIER_MEDIUM    = 3; // 100K-1M
    const TIER_LOW       = 4; // 10K-100K
    const TIER_VERY_LOW  = 5; // 1K-10K
    const TIER_EXPERIMENTAL = 6; // <1K
    const TIER_UNKNOWN   = 0; // not in dataset

    /**
     * Type -> bucket string from Google 2026_05 stats.
     *
     * @return array<string,string>
     */
    public static function types(): array {
        return array(
            // 10M+ domains (12 types) — Tier 1
            'BreadcrumbList'          => '10M+',
            'EntryPoint'              => '10M+',
            'ImageObject'             => '10M+',
            'ListItem'                => '10M+',
            'Organization'            => '10M+',
            'Person'                  => '10M+',
            'WebPage'                 => '10M+',
            'WebSite'                 => '10M+',

            // 1M-10M domains — Tier 2
            'AggregateOffer'          => '1M-10M',
            'AggregateRating'         => '1M-10M',
            'Answer'                  => '1M-10M',
            'Article'                 => '1M-10M',
            'Blog'                    => '1M-10M',
            'BlogPosting'             => '1M-10M',
            'Brand'                   => '1M-10M',
            'CollectionPage'          => '1M-10M',
            'ContactPoint'            => '1M-10M',
            'FAQPage'                 => '1M-10M',
            'ItemList'                => '1M-10M',
            'ItemPage'                => '1M-10M',
            'LocalBusiness'           => '1M-10M',
            'Offer'                   => '1M-10M',
            'OpeningHoursSpecification' => '1M-10M',
            'Place'                   => '1M-10M',
            'PostalAddress'           => '1M-10M',
            'Product'                 => '1M-10M',
            'QuantitativeValue'       => '1M-10M',
            'Question'                => '1M-10M',
            'Rating'                  => '1M-10M',
            'Review'                  => '1M-10M',
            'Service'                 => '1M-10M',
            'SiteNavigationElement'   => '1M-10M',
            'VideoObject'             => '1M-10M',

            // 100K-1M domains — Tier 3
            'AboutPage'               => '100K-1M',
            'ContactPage'             => '100K-1M',
            'DefinedRegion'           => '100K-1M',
            'EducationalOrganization' => '100K-1M',
            'Event'                   => '100K-1M',
            'HowTo'                   => '100K-1M',
            'HowToStep'               => '100K-1M',
            'InteractionCounter'      => '100K-1M',
            'JobPosting'              => '100K-1M',
            'MerchantReturnPolicy'    => '100K-1M',
            'MonetaryAmount'          => '100K-1M',
            'NewsArticle'             => '100K-1M',
            'OfferCatalog'            => '100K-1M',
            'OfferShippingDetails'    => '100K-1M',
            'OnlineStore'             => '100K-1M',
            'PriceSpecification'      => '100K-1M',
            'ProductGroup'            => '100K-1M',
            'ProfessionalService'     => '100K-1M',
            'ProfilePage'             => '100K-1M',
            'PropertyValue'           => '100K-1M',
            'Restaurant'              => '100K-1M',
            'ShippingDeliveryTime'    => '100K-1M',
            'SoftwareApplication'     => '100K-1M',
            'SpeakableSpecification'  => '100K-1M',
            'Store'                   => '100K-1M',
            'WebApplication'          => '100K-1M',

            // 10K-100K — Tier 4 (niche)
            'AudioObject'             => '10K-100K',
            'CheckoutPage'            => '10K-100K',
            'Course'                  => '10K-100K',
            'DefinedTerm'             => '10K-100K',
            'DiscussionForumPosting'  => '10K-100K',
            'EducationalOccupationalCredential' => '10K-100K',
            'PodcastSeries'           => '10K-100K',
            'QAPage'                  => '10K-100K',
            'Recipe'                  => '10K-100K',
            'OrganizationRole'        => '10K-100K',

            // 1K-10K — Tier 5 (very niche)
            'ClaimReview'             => '1K-10K',
            'NewsMediaOrganization'   => '1K-10K',
            'PodcastEpisode'          => '1K-10K',
        );
    }

    /**
     * Get the bucket string for a schema.org type. Empty string if not in dataset.
     */
    public static function bucket( string $type ): string {
        return self::types()[ $type ] ?? '';
    }

    /**
     * Get the tier (1=widest adoption, 5=very niche, 0=unknown).
     */
    public static function tier( string $type ): int {
        $bucket = self::bucket( $type );
        switch ( $bucket ) {
            case '10M+':      return self::TIER_VERY_HIGH;
            case '1M-10M':    return self::TIER_HIGH;
            case '100K-1M':   return self::TIER_MEDIUM;
            case '10K-100K':  return self::TIER_LOW;
            case '1K-10K':    return self::TIER_VERY_LOW;
            case '<1K':       return self::TIER_EXPERIMENTAL;
            default:          return self::TIER_UNKNOWN;
        }
    }

    /**
     * Render an inline HTML badge for a type. Used in the post meta-box and
     * settings UI to show users how widely a type is adopted before they
     * enable it. Returns empty string for unknown types (no badge shown).
     *
     * Colors:
     *   green  = tier 1-2 (mainstream, very safe)
     *   blue   = tier 3   (established, safe)
     *   yellow = tier 4-5 (niche, deliberate choice)
     *   red    = experimental (<1K, expect quirks)
     */
    public static function badge_html( string $type ): string {
        $bucket = self::bucket( $type );
        if ( $bucket === '' ) {
            return '';
        }
        $tier = self::tier( $type );
        if ( $tier <= self::TIER_HIGH ) {
            $bg  = '#dcfce7';
            $col = '#166534';
            $label_pl = __( 'powszechne', 'ligase' );
        } elseif ( $tier === self::TIER_MEDIUM ) {
            $bg  = '#dbeafe';
            $col = '#1e40af';
            $label_pl = __( 'ustabilizowane', 'ligase' );
        } elseif ( $tier <= self::TIER_VERY_LOW ) {
            $bg  = '#fef3c7';
            $col = '#92400e';
            $label_pl = __( 'niszowe', 'ligase' );
        } else {
            $bg  = '#fee2e2';
            $col = '#991b1b';
            $label_pl = __( 'eksperymentalne', 'ligase' );
        }
        return sprintf(
            '<span title="%1$s" style="display:inline-block;font-size:10px;font-weight:600;padding:1px 6px;margin-left:6px;border-radius:8px;background:%2$s;color:%3$s;letter-spacing:.02em;">%4$s · %5$s</span>',
            esc_attr( sprintf( __( '%1$s — używany w %2$s domen wg Google open-web crawl 2026-05', 'ligase' ), $type, $bucket ) ),
            esc_attr( $bg ),
            esc_attr( $col ),
            esc_html( $bucket ),
            esc_html( $label_pl )
        );
    }
}
