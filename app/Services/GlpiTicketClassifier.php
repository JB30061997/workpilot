<?php

namespace App\Services;

use Illuminate\Support\Str;

class GlpiTicketClassifier
{
    private const SOLUTIONS = [
        'Odoo' => [
            'odoo',
            'odoo.sh',
        ],
        'Sage' => [
            'sage',
            'sage 1000',
            'sage1000',
            'sage paie',
            'sage x3',
        ],
        'Moovapps' => [
            'moovapps',
            'moovapp',
            'moov apps',
        ],
        'AGIRH' => [
            'agirh',
            'agi rh',
        ],
        'Pronote' => [
            'pronote',
        ],
        'Microsoft 365' => [
            'office 365',
            'microsoft 365',
            'sharepoint',
            'teams',
            'onedrive',
        ],
        'SAP' => [
            'sap',
            'sap hana',
            's4hana',
        ],
        'Power BI' => [
            'power bi',
            'powerbi',
        ],
        'Autres solutions' => [
            'plateforme',
            'platform',
            'portail',
            'application',
            'logiciel',
            'erp',
            'web service',
            'site web',
            'interface',
            'module applicatif',
        ],
    ];

    private const INFRASTRUCTURE = [
        'Réseau & Internet' => [
            'wifi',
            'wi fi',
            'reseau',
            'cable',
            'ethernet',
            'switch',
            'routeur',
            'internet',
            'connexion internet',
            'fibre',
            'vpn',
            'firewall',
            'pare feu',
            'lan',
            'wan',
        ],
        'Serveurs & Systèmes' => [
            'serveur',
            'server',
            'windows server',
            'linux',
            'vmware',
            'hyperviseur',
            'machine virtuelle',
            'virtualisation',
            'active directory',
            'dns',
            'dhcp',
        ],
        'Matériel informatique' => [
            'ordinateur',
            'pc',
            'laptop',
            'imprimante',
            'scanner',
            'ecran',
            'clavier',
            'souris',
            'photocopieur',
            'projecteur',
            'videoprojecteur',
            'materiel informatique',
        ],
        'Messagerie' => [
            'outlook',
            'gmail',
            'boite mail',
            'adresse mail',
            'messagerie',
            'email',
            'e mail',
            'courriel',
        ],
        'Téléphonie' => [
            'telephone',
            'telephonie',
            'standard telephonique',
            'telephone fixe',
            'voip',
            'ligne telephonique',
        ],
        'Sécurité informatique' => [
            'antivirus',
            'malware',
            'virus',
            'cybersecurite',
            'phishing',
            'poste infecte',
        ],
    ];

    public function classify(?string $subject): array
    {
        $normalized = $this->normalize($subject ?? '');

        if ($normalized === '') {
            return $this->unknown();
        }

        // Priorité aux applications explicitement nommées.
        foreach (self::SOLUTIONS as $category => $keywords) {
            if ($category === 'Autres solutions') {
                continue;
            }

            if ($this->matches($normalized, $keywords)) {
                return $this->result(
                    'solutions',
                    $category,
                    'high'
                );
            }
        }

        // Infrastructure avec termes techniques précis.
        foreach (self::INFRASTRUCTURE as $category => $keywords) {
            if ($this->matches($normalized, $keywords)) {
                return $this->result(
                    'infrastructure',
                    $category,
                    'high'
                );
            }
        }

        // Solutions génériques.
        if ($this->matches(
            $normalized,
            self::SOLUTIONS['Autres solutions']
        )) {
            return $this->result(
                'solutions',
                'Autres solutions',
                'medium'
            );
        }

        return $this->unknown();
    }

    private function matches(
        string $subject,
        array $keywords
    ): bool {
        foreach ($keywords as $keyword) {
            $keyword = $this->normalize($keyword);

            if ($keyword === '') {
                continue;
            }

            // Évite les correspondances partielles
            // comme "sap" dans un autre mot.
            $pattern = '/(?<![a-z0-9])'
                .preg_quote($keyword, '/')
                .'(?![a-z0-9])/u';

            if (preg_match($pattern, $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        // Convertit les caractères accentués :
        // câble -> cable, réseau -> reseau
        $value = Str::ascii($value);

        // Convertit en minuscules
        $value = strtolower($value);

        // Supprime les caractères spéciaux
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        // Nettoie les espaces
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function result(
        string $domain,
        string $category,
        string $confidence
    ): array {
        return [
            'domain' => $domain,
            'category' => $category,
            'confidence' => $confidence,
        ];
    }

    private function unknown(): array
    {
        return $this->result(
            'unknown',
            'Non identifié',
            'low'
        );
    }
}
