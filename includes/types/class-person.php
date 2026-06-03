<?php
/**
 * Ligase - Person schema type
 *
 * Builds the Person @graph node for an author. Combines:
 *   - WordPress core user data (display_name, first_name, last_name, user_email,
 *     user_url, description, avatar)
 *   - WordPress user contact methods (facebook, instagram, linkedin, x-username,
 *     youtube, pinterest, wikipedia, myspace, soundcloud, tumblr) — added by
 *     Yoast, theme, or `user_contactmethods` filter; auto-mapped to sameAs[]
 *   - Ligase-specific structured fields (jobTitle, knowsAbout, alumniOf details,
 *     credentials, memberOf, knowsLanguage, telephone) from `ligase_*` user_meta
 *
 * E-E-A-T payoff: a complete Person node with sameAs to LinkedIn/Wikidata +
 * hasCredential (license/degree/certification) + memberOf (professional body) +
 * alumniOf is the single strongest author-authority signal a WordPress site can
 * emit to Google AI Overviews and citation-eligible LLM retrieval.
 *
 * @package Ligase
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Person {
    private int $user_id;

    public function __construct( int $user_id ) {
        $this->user_id = $user_id;
    }

    public function build(): ?array {
        $user = get_userdata( $this->user_id );
        if ( ! $user ) {
            return null;
        }

        $schema = [
            '@type' => 'Person',
            '@id'   => home_url( '/#author-' . $this->user_id ),
            'name'  => wp_strip_all_tags( $user->display_name ),
            'url'   => esc_url( get_author_posts_url( $this->user_id ) ),
        ];

        // Name decomposition — pull from WP first_name/last_name if set, else
        // try to derive from display_name. Ligase fields override WP defaults.
        $given  = (string) ( get_user_meta( $this->user_id, 'ligase_given_name',  true ) ?: $user->first_name );
        $family = (string) ( get_user_meta( $this->user_id, 'ligase_family_name', true ) ?: $user->last_name );
        if ( ! $given && ! $family && $user->display_name ) {
            // Best-effort split on first space (handles "Jan Kowalski" but not "Jan Maria Kowalski").
            $parts = preg_split( '/\s+/', trim( (string) $user->display_name ), 2 );
            if ( is_array( $parts ) && count( $parts ) === 2 ) {
                [ $given, $family ] = $parts;
            }
        }
        if ( $given ) {
            $schema['givenName'] = wp_strip_all_tags( $given );
        }
        if ( $family ) {
            $schema['familyName'] = wp_strip_all_tags( $family );
        }

        // Bio
        if ( $user->description ) {
            $schema['description'] = wp_strip_all_tags( $user->description );
        }

        // Honorific (mgr / dr / prof)
        $honorific = get_user_meta( $this->user_id, 'ligase_honorific', true );
        if ( $honorific ) {
            $schema['honorificPrefix'] = wp_strip_all_tags( $honorific );
        }

        // Job title
        $job = get_user_meta( $this->user_id, 'ligase_job_title', true );
        if ( $job ) {
            $schema['jobTitle'] = wp_strip_all_tags( $job );
        }

        // Email — WP user account email. Opt-in via `ligase_publish_email`
        // (default off) so private accounts don't leak their addresses to
        // every JSON-LD consumer.
        if ( get_user_meta( $this->user_id, 'ligase_publish_email', true ) === '1' && $user->user_email ) {
            $schema['email'] = sanitize_email( $user->user_email );
        }

        // Telephone — Person-specific (separate from Organization phone)
        $tel = get_user_meta( $this->user_id, 'ligase_telephone', true );
        if ( $tel ) {
            $schema['telephone'] = wp_strip_all_tags( $tel );
        }

        // knowsLanguage — CSV like "pl, en" → ["pl", "en"]
        $langs = get_user_meta( $this->user_id, 'ligase_knows_language', true );
        if ( $langs ) {
            $list = array_values( array_filter( array_map( 'trim', explode( ',', (string) $langs ) ) ) );
            if ( $list ) {
                $schema['knowsLanguage'] = $list;
            }
        }

        // knowsAbout — CSV → array
        $knows = get_user_meta( $this->user_id, 'ligase_knows_about', true );
        if ( $knows ) {
            $schema['knowsAbout'] = array_values( array_filter( array_map(
                fn( $t ) => wp_strip_all_tags( trim( $t ) ),
                explode( ',', (string) $knows )
            ) ) );
        }

        // alumniOf — promoted from plain string to EducationalOrganization with
        // optional URL + department for richer entity linkage.
        $alumni_name = (string) get_user_meta( $this->user_id, 'ligase_alumni_of', true );
        if ( $alumni_name ) {
            $alumni_url  = (string) get_user_meta( $this->user_id, 'ligase_alumni_of_url',  true );
            $alumni_dept = (string) get_user_meta( $this->user_id, 'ligase_alumni_of_dept', true );
            $node = [
                '@type' => 'EducationalOrganization',
                'name'  => wp_strip_all_tags( $alumni_name ),
            ];
            if ( $alumni_url ) {
                $node['url'] = esc_url( $alumni_url );
            }
            if ( $alumni_dept ) {
                $node['department'] = wp_strip_all_tags( $alumni_dept );
            }
            $schema['alumniOf'] = $node;
        }

        // hasCredential — repeater stored as one credential per line in user_meta:
        //   Name | category | Issuer name | Issuer URL | identifier? | year?
        // Categories accepted: license / degree / certification / membership / award
        // Legacy single string (`ligase_credential`) is preserved as a fallback.
        $credentials_raw = (string) get_user_meta( $this->user_id, 'ligase_credentials', true );
        $credentials = $this->parse_credentials( $credentials_raw );
        if ( empty( $credentials ) ) {
            // Legacy fallback: single credential as plain text.
            $legacy = (string) get_user_meta( $this->user_id, 'ligase_credential', true );
            if ( $legacy ) {
                $credentials = [ [
                    '@type' => 'EducationalOccupationalCredential',
                    'name'  => wp_strip_all_tags( $legacy ),
                ] ];
            }
        }
        if ( ! empty( $credentials ) ) {
            $schema['hasCredential'] = count( $credentials ) === 1 ? $credentials[0] : $credentials;
        }

        // memberOf — Organizations the person belongs to (Bar Association,
        // professional bodies, etc.). One per line: "Name | URL".
        $member_raw = (string) get_user_meta( $this->user_id, 'ligase_member_of', true );
        $member_of  = $this->parse_member_of( $member_raw );
        if ( ! empty( $member_of ) ) {
            $schema['memberOf'] = count( $member_of ) === 1 ? $member_of[0] : $member_of;
        }

        // sameAs — merge from:
        //   1. Ligase fields (ligase_linkedin, ligase_twitter, ligase_wikidata) — legacy
        //   2. WordPress contact methods (facebook, instagram, linkedin, x-username,
        //      youtube, pinterest, wikipedia, myspace, soundcloud, tumblr) — auto
        //   3. User Website (user_url)
        $schema['sameAs'] = $this->collect_same_as( $user );
        if ( empty( $schema['sameAs'] ) ) {
            unset( $schema['sameAs'] );
        }

        // Image — prefer explicit ligase_image_url meta, fall back to gravatar
        $img_url = (string) get_user_meta( $this->user_id, 'ligase_image_url', true );
        if ( ! $img_url ) {
            $img_url = (string) ( get_avatar_url( $this->user_id, [ 'size' => 400 ] ) ?: '' );
        }
        if ( $img_url ) {
            $schema['image'] = [
                '@type' => 'ImageObject',
                'url'   => esc_url( $img_url ),
            ];
        }

        // worksFor — by default graph-linked to the site Organization, but authors
        // who run their own firm (kancelaria / consulting) can override with an
        // external Organization. Without override, the @id reference saves payload
        // and consolidates the publisher entity across the graph.
        $ext_works_name = (string) get_user_meta( $this->user_id, 'ligase_works_for_name', true );
        $ext_works_url  = (string) get_user_meta( $this->user_id, 'ligase_works_for_url',  true );
        if ( $ext_works_name !== '' ) {
            $works_for = [
                '@type' => 'Organization',
                'name'  => wp_strip_all_tags( $ext_works_name ),
            ];
            if ( $ext_works_url !== '' ) {
                $works_for['url'] = esc_url( $ext_works_url );
            }
            $schema['worksFor'] = $works_for;
        } else {
            $schema['worksFor'] = [ '@id' => home_url( '/#org' ) ];
        }

        // affiliation — loose ties (industry associations, advisory boards) where
        // the person isn't a formal member. Schema.org distinguishes memberOf
        // (formal membership) from affiliation (cooperation / association).
        $affiliation_raw = (string) get_user_meta( $this->user_id, 'ligase_affiliation', true );
        $affiliation     = $this->parse_member_of( $affiliation_raw );
        if ( ! empty( $affiliation ) ) {
            $schema['affiliation'] = count( $affiliation ) === 1 ? $affiliation[0] : $affiliation;
        }

        // subjectOf — external articles / interviews where this person is the topic.
        // Strong Knowledge Graph signal: "this entity is written about by these
        // independent publications." Format per line: "Title | URL".
        $subject_raw = (string) get_user_meta( $this->user_id, 'ligase_subject_of', true );
        $subject_of  = $this->parse_subject_of( $subject_raw );
        if ( ! empty( $subject_of ) ) {
            $schema['subjectOf'] = count( $subject_of ) === 1 ? $subject_of[0] : $subject_of;
        }

        // workExperience — structured career history as OrganizationRole. More
        // expressive than a single jobTitle string, useful for personal-brand sites.
        // Format per line: "Role | Org name | Org URL | startYear | endYear?".
        $we_raw     = (string) get_user_meta( $this->user_id, 'ligase_work_experience', true );
        $experience = $this->parse_work_experience( $we_raw );
        if ( ! empty( $experience ) ) {
            $schema['workExperience'] = count( $experience ) === 1 ? $experience[0] : $experience;
        }

        // award — recognition received from external bodies. Plain string per line,
        // or "Name | Issuer | year" for structured awards. Falls back to string array
        // when no issuer is given (Google accepts both shapes).
        $award_raw = (string) get_user_meta( $this->user_id, 'ligase_award', true );
        $awards    = $this->parse_awards( $award_raw );
        if ( ! empty( $awards ) ) {
            $schema['award'] = count( $awards ) === 1 ? $awards[0] : $awards;
        }

        // mainEntityOfPage — author archive
        $author_url = get_author_posts_url( $this->user_id );
        if ( $author_url ) {
            $schema['mainEntityOfPage'] = esc_url( $author_url );
        }

        return apply_filters( 'ligase_person', $schema, $this->user_id );
    }

    /**
     * Parse credentials textarea (one per line) into EducationalOccupationalCredential nodes.
     *
     * Line format: `Name | category | Issuer name | Issuer URL | identifier? | year?`
     * Empty trailing fields are allowed; only `Name` is required.
     *
     * @return array<int,array>
     */
    private function parse_credentials( string $raw ): array {
        if ( $raw === '' ) {
            return [];
        }
        $category_map = [
            'license'       => 'license',
            'degree'        => 'degree',
            'certification' => 'certification',
            'membership'    => 'membership',
            'award'         => 'award',
        ];
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $parts = array_map( 'trim', explode( '|', $line ) );
            $name  = (string) ( $parts[0] ?? '' );
            if ( $name === '' ) {
                continue;
            }
            $node = [
                '@type' => 'EducationalOccupationalCredential',
                'name'  => wp_strip_all_tags( $name ),
            ];
            $category = strtolower( (string) ( $parts[1] ?? '' ) );
            if ( $category && isset( $category_map[ $category ] ) ) {
                $node['credentialCategory'] = $category_map[ $category ];
            }
            $issuer_name = (string) ( $parts[2] ?? '' );
            $issuer_url  = (string) ( $parts[3] ?? '' );
            if ( $issuer_name || $issuer_url ) {
                $issuer = [ '@type' => 'Organization' ];
                if ( $issuer_name ) {
                    $issuer['name'] = wp_strip_all_tags( $issuer_name );
                }
                if ( $issuer_url ) {
                    $issuer['url'] = esc_url( $issuer_url );
                }
                $node['recognizedBy'] = $issuer;
            }
            $identifier = (string) ( $parts[4] ?? '' );
            if ( $identifier ) {
                $node['identifier'] = wp_strip_all_tags( $identifier );
            }
            $year = (string) ( $parts[5] ?? '' );
            if ( $year && preg_match( '/^\d{4}$/', $year ) ) {
                $node['dateCreated'] = $year;
            }
            $out[] = $node;
        }
        return $out;
    }

    /**
     * Parse memberOf textarea — `Name | URL` per line. URL optional.
     *
     * @return array<int,array>
     */
    private function parse_member_of( string $raw ): array {
        if ( $raw === '' ) {
            return [];
        }
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $parts = array_map( 'trim', explode( '|', $line ) );
            $name  = (string) ( $parts[0] ?? '' );
            if ( $name === '' ) {
                continue;
            }
            $node = [ '@type' => 'Organization', 'name' => wp_strip_all_tags( $name ) ];
            $url  = (string) ( $parts[1] ?? '' );
            if ( $url ) {
                $node['url'] = esc_url( $url );
            }
            $out[] = $node;
        }
        return $out;
    }

    /**
     * Collect every social profile / external identity URL for sameAs[].
     *
     * Sources (deduplicated by normalized URL):
     *   - WordPress core: user_url
     *   - WordPress contact methods (added by themes/Yoast): facebook,
     *     instagram, linkedin, x-username (handle only — wrapped to URL),
     *     twitter (legacy), youtube, pinterest, wikipedia, myspace,
     *     soundcloud, tumblr
     *   - Ligase legacy fields: ligase_linkedin, ligase_twitter, ligase_wikidata
     *
     * @return string[] List of valid HTTPS/HTTP URLs.
     */
    private function collect_same_as( WP_User $user ): array {
        $candidates = [];

        // 1. Site URL
        if ( $user->user_url ) {
            $candidates[] = (string) $user->user_url;
        }

        // 2. WP contact methods (added by themes / Yoast / filter)
        $contact_keys = [
            'facebook', 'instagram', 'linkedin', 'twitter', 'youtube',
            'pinterest', 'wikipedia', 'myspace', 'soundcloud', 'tumblr',
        ];
        foreach ( $contact_keys as $key ) {
            $val = get_user_meta( $this->user_id, $key, true );
            if ( is_string( $val ) && $val !== '' ) {
                $candidates[] = $val;
            }
        }

        // 3. x-username — Yoast 21+ stores just the handle (no @, no URL).
        //    Wrap to a full URL so sameAs is consumable.
        $x_handle = (string) get_user_meta( $this->user_id, 'x-username', true );
        if ( $x_handle !== '' ) {
            $candidates[] = 'https://x.com/' . ltrim( $x_handle, '@/' );
        }

        // 4. Ligase legacy fields
        foreach ( [ 'ligase_linkedin', 'ligase_twitter', 'ligase_wikidata' ] as $key ) {
            $val = get_user_meta( $this->user_id, $key, true );
            if ( is_string( $val ) && $val !== '' ) {
                // Special case: ligase_twitter might be a handle, normalize like x-username.
                if ( $key === 'ligase_twitter' && ! preg_match( '#^https?://#', $val ) ) {
                    $val = 'https://x.com/' . ltrim( $val, '@/' );
                }
                $candidates[] = $val;
            }
        }

        // 5. Extra sameAs textarea (one URL per line) for ORCID, Wikipedia, Google Scholar etc.
        $extra = (string) get_user_meta( $this->user_id, 'ligase_extra_sameas', true );
        if ( $extra !== '' ) {
            foreach ( preg_split( '/\r\n|\r|\n/', $extra ) ?: [] as $line ) {
                $line = trim( $line );
                if ( $line !== '' ) {
                    $candidates[] = $line;
                }
            }
        }

        // Validate + dedupe
        $seen = [];
        $out  = [];
        foreach ( $candidates as $url ) {
            $url    = esc_url_raw( $url );
            if ( $url === '' ) {
                continue;
            }
            $parsed = wp_parse_url( $url );
            if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
                continue;
            }
            if ( ! in_array( $parsed['scheme'], [ 'http', 'https' ], true ) ) {
                continue;
            }
            $key = strtolower( $parsed['host'] . ( $parsed['path'] ?? '' ) );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[] = $url;
        }
        return $out;
    }

    /**
     * Parse subjectOf textarea — `Title | URL` per line. Both required;
     * lines missing either part are skipped. Emits Article nodes (not
     * CreativeWork) because Google's Knowledge Graph treats Article subjects
     * as a stronger authority signal for the person entity.
     *
     * @return array<int,array>
     */
    private function parse_subject_of( string $raw ): array {
        if ( $raw === '' ) {
            return [];
        }
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $parts = array_map( 'trim', explode( '|', $line ) );
            $title = (string) ( $parts[0] ?? '' );
            $url   = (string) ( $parts[1] ?? '' );
            if ( $title === '' || $url === '' ) {
                continue;
            }
            $out[] = [
                '@type' => 'Article',
                'name'  => wp_strip_all_tags( $title ),
                'url'   => esc_url( $url ),
            ];
        }
        return $out;
    }

    /**
     * Parse workExperience textarea — `Role | Org | URL? | startYear? | endYear?` per line.
     * Emits OrganizationRole nodes with nested Organization. Role + Org are required,
     * URL / dates optional. Years can be either YYYY or YYYY-MM-DD.
     *
     * @return array<int,array>
     */
    private function parse_work_experience( string $raw ): array {
        if ( $raw === '' ) {
            return [];
        }
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $parts = array_map( 'trim', explode( '|', $line ) );
            $role  = (string) ( $parts[0] ?? '' );
            $org   = (string) ( $parts[1] ?? '' );
            if ( $role === '' || $org === '' ) {
                continue;
            }
            $org_url = (string) ( $parts[2] ?? '' );
            $start   = (string) ( $parts[3] ?? '' );
            $end     = (string) ( $parts[4] ?? '' );

            $org_node = [ '@type' => 'Organization', 'name' => wp_strip_all_tags( $org ) ];
            if ( $org_url !== '' ) {
                $org_node['url'] = esc_url( $org_url );
            }
            $role_node = [
                '@type'    => 'OrganizationRole',
                'roleName' => wp_strip_all_tags( $role ),
                'memberOf' => $org_node,
            ];
            if ( preg_match( '/^\d{4}(-\d{2}(-\d{2})?)?$/', $start ) ) {
                $role_node['startDate'] = $start;
            }
            if ( preg_match( '/^\d{4}(-\d{2}(-\d{2})?)?$/', $end ) ) {
                $role_node['endDate'] = $end;
            }
            $out[] = $role_node;
        }
        return $out;
    }

    /**
     * Parse awards textarea — either a plain string per line ("Diamenty Forbesa 2023")
     * or a structured "Name | Issuer | year" line. Plain-string lines come back as
     * raw strings (Google accepts string-or-object); structured lines become full
     * award objects so the issuer is searchable.
     *
     * @return array<int, string|array>
     */
    private function parse_awards( string $raw ): array {
        if ( $raw === '' ) {
            return [];
        }
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            if ( strpos( $line, '|' ) === false ) {
                $out[] = wp_strip_all_tags( $line );
                continue;
            }
            $parts  = array_map( 'trim', explode( '|', $line ) );
            $name   = (string) ( $parts[0] ?? '' );
            $issuer = (string) ( $parts[1] ?? '' );
            $year   = (string) ( $parts[2] ?? '' );
            if ( $name === '' ) {
                continue;
            }
            // schema.org/award accepts plain text or a Thing. Use the simple
            // text+separator shape ("Award name (Issuer, 2023)") because Google's
            // Person doc explicitly shows award as text. Keeps the SERP clean.
            $label = $name;
            if ( $issuer !== '' ) {
                $label .= ' (' . $issuer;
                if ( $year !== '' ) {
                    $label .= ', ' . $year;
                }
                $label .= ')';
            } elseif ( $year !== '' ) {
                $label .= ' (' . $year . ')';
            }
            $out[] = wp_strip_all_tags( $label );
        }
        return $out;
    }
}
