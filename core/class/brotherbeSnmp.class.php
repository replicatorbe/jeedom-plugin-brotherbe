<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Client SNMP v2c réduit à ce dont le plugin a besoin : une requête GET sur
 * une liste d'OID, par UDP.
 *
 * Écrit à la main plutôt que de s'appuyer sur l'extension php-snmp : elle ne
 * fait pas partie d'une installation Jeedom standard, et l'installer demande un
 * paquet système puis un redémarrage d'Apache — beaucoup d'étapes pour une
 * dizaine d'OID. Le protocole, lui, tient en quelques règles d'encodage BER.
 *
 * Aucune dépendance au coeur de Jeedom : la classe se rejoue telle quelle dans
 * les tests hors ligne.
 */
class brotherbeSnmp {

    const PORT = 161;
    const VERSION_2C = 1;

    /* Étiquettes BER des types rencontrés dans une réponse. */
    const TAG_INTEGER      = 0x02;
    const TAG_OCTET_STRING = 0x04;
    const TAG_NULL         = 0x05;
    const TAG_OID          = 0x06;
    const TAG_SEQUENCE     = 0x30;
    const TAG_IPADDRESS    = 0x40;
    const TAG_COUNTER32    = 0x41;
    const TAG_GAUGE32      = 0x42;
    const TAG_TIMETICKS    = 0x43;
    const TAG_OPAQUE       = 0x44;
    const TAG_COUNTER64    = 0x46;
    const TAG_NO_SUCH_OBJECT   = 0x80;
    const TAG_NO_SUCH_INSTANCE = 0x81;
    const TAG_END_OF_MIB_VIEW  = 0x82;
    const TAG_GET_REQUEST  = 0xA0;
    const TAG_RESPONSE     = 0xA2;

    /*
     * Interroge un agent et rend array(oid => valeur) pour chaque OID qui
     * existe. Un OID que l'imprimante ne connaît pas est simplement absent du
     * résultat : c'est ainsi qu'une monochrome dit qu'elle n'a pas de toner cyan.
     *
     * Les valeurs sont typées : entier pour les nombres, chaîne d'octets brute
     * pour les OCTET STRING (le décodage appartient à l'appelant, qui seul sait
     * s'il s'agit de texte ou d'un bloc binaire), chaîne pointée pour un OID.
     *
     * Une imprimante en veille profonde laisse parfois tomber le premier paquet
     * le temps de se réveiller : d'où les nouvelles tentatives.
     */
    public static function get($_host, $_community, $_oids, $_timeout = 2, $_retries = 2) {
        $host = trim((string) $_host);
        if ($host === '') {
            throw new Exception('Aucune adresse n\'est renseignée.');
        }
        $oids = array_values(array_unique($_oids));
        if (count($oids) === 0) {
            return array();
        }

        /* Une adresse IPv6 doit être entre crochets dans une URL de socket. */
        $target = (strpos($host, ':') !== false && $host[0] !== '[') ? '[' . $host . ']' : $host;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('udp://' . $target . ':' . self::PORT, $errno, $errstr, $_timeout);
        if ($socket === false) {
            throw new Exception(sprintf('Impossible d\'ouvrir une socket vers %s : %s', $host, $errstr));
        }

        try {
            $result = self::exchange($socket, $_community, $oids, $_timeout, $_retries);

            /*
             * Certains agents anciens rejettent toute la requête dès qu'un seul
             * OID leur est inconnu, au lieu de répondre noSuchObject pour
             * celui-là. On repasse alors OID par OID : plus lent, mais on
             * récupère tout ce qui existe.
             */
            if ($result['error'] !== 0 && count($oids) > 1) {
                $values = array();
                foreach ($oids as $oid) {
                    $single = self::exchange($socket, $_community, array($oid), $_timeout, $_retries);
                    if ($single['error'] === 0) {
                        $values = $values + $single['values'];
                    }
                }
                return $values;
            }
            if ($result['error'] !== 0) {
                return array();
            }
            return $result['values'];
        } finally {
            fclose($socket);
        }
    }

    /* Un aller-retour, avec ses nouvelles tentatives. */
    private static function exchange($_socket, $_community, $_oids, $_timeout, $_retries) {
        $requestId = mt_rand(1, 0x7FFFFFFF);
        $packet = self::encodeGetRequest($_community, $requestId, $_oids);

        for ($attempt = 0; $attempt <= $_retries; $attempt++) {
            if (@fwrite($_socket, $packet) === false) {
                throw new Exception('Envoi de la requête SNMP impossible.');
            }

            $deadline = microtime(true) + $_timeout;
            while (($left = $deadline - microtime(true)) > 0) {
                $read = array($_socket);
                $write = null;
                $except = null;
                $ready = @stream_select($read, $write, $except, (int) floor($left), (int) (($left - floor($left)) * 1000000));
                if ($ready === false) {
                    throw new Exception('Attente de la réponse SNMP impossible.');
                }
                if ($ready === 0) {
                    break;
                }
                $answer = @fread($_socket, 65535);
                if ($answer === false || $answer === '') {
                    /* Un port fermé côté imprimante revient en ICMP, que PHP
                     * traduit par une lecture vide : inutile d'insister. */
                    throw new Exception('Aucun agent SNMP ne répond sur cette adresse.');
                }
                try {
                    return self::decodeResponse($answer, $requestId);
                } catch (UnexpectedValueException $e) {
                    /* La réponse tardive d'une tentative précédente : on
                     * l'écarte et on continue d'attendre la bonne. */
                    continue;
                }
            }
        }
        throw new Exception('L\'imprimante ne répond pas en SNMP (port UDP 161). Vérifiez l\'adresse, que SNMP est activé sur l\'imprimante et que la communauté est la bonne : une imprimante ignore sans rien dire une communauté qu\'elle ne connaît pas.');
    }

    /* ============================================================= ENCODAGE */

    public static function encodeGetRequest($_community, $_requestId, $_oids) {
        $varbinds = '';
        foreach ($_oids as $oid) {
            $varbinds .= self::tlv(self::TAG_SEQUENCE, self::encodeOid($oid) . self::tlv(self::TAG_NULL, ''));
        }
        $pdu = self::tlv(self::TAG_GET_REQUEST,
            self::encodeInteger($_requestId)
            . self::encodeInteger(0)          /* error-status */
            . self::encodeInteger(0)          /* error-index */
            . self::tlv(self::TAG_SEQUENCE, $varbinds));
        return self::tlv(self::TAG_SEQUENCE,
            self::encodeInteger(self::VERSION_2C)
            . self::tlv(self::TAG_OCTET_STRING, (string) $_community)
            . $pdu);
    }

    private static function tlv($_tag, $_content) {
        return chr($_tag) . self::encodeLength(strlen($_content)) . $_content;
    }

    private static function encodeLength($_length) {
        if ($_length < 0x80) {
            return chr($_length);
        }
        $bytes = '';
        while ($_length > 0) {
            $bytes = chr($_length & 0xFF) . $bytes;
            $_length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /* Entier signé en complément à deux, sur le plus petit nombre d'octets. */
    private static function encodeInteger($_value) {
        $value = (int) $_value;
        $bytes = '';
        do {
            $bytes = chr($value & 0xFF) . $bytes;
            $value >>= 8;
        } while (!(($value === 0 && (ord($bytes[0]) & 0x80) === 0) || ($value === -1 && (ord($bytes[0]) & 0x80) !== 0)));
        return self::tlv(self::TAG_INTEGER, $bytes);
    }

    public static function encodeOid($_oid) {
        $parts = array_map('intval', explode('.', trim((string) $_oid, '.')));
        if (count($parts) < 2) {
            throw new Exception('OID invalide : ' . $_oid);
        }
        $content = chr(40 * $parts[0] + $parts[1]);
        for ($i = 2; $i < count($parts); $i++) {
            $content .= self::encodeBase128($parts[$i]);
        }
        return self::tlv(self::TAG_OID, $content);
    }

    private static function encodeBase128($_value) {
        $bytes = chr($_value & 0x7F);
        $_value >>= 7;
        while ($_value > 0) {
            $bytes = chr(0x80 | ($_value & 0x7F)) . $bytes;
            $_value >>= 7;
        }
        return $bytes;
    }

    /* ============================================================= DÉCODAGE */

    /*
     * Rend array('error' => error-status, 'values' => array(oid => valeur)).
     * Lève UnexpectedValueException pour un paquet qui n'est pas la réponse
     * attendue — un autre identifiant de requête, ou des octets illisibles.
     */
    public static function decodeResponse($_packet, $_requestId = null) {
        $offset = 0;
        $message = self::readTlv($_packet, $offset);
        if ($message['tag'] !== self::TAG_SEQUENCE) {
            throw new UnexpectedValueException('Réponse SNMP illisible.');
        }

        $inner = 0;
        self::readTlv($message['value'], $inner);                 /* version */
        self::readTlv($message['value'], $inner);                 /* communauté */
        $pdu = self::readTlv($message['value'], $inner);
        if ($pdu['tag'] !== self::TAG_RESPONSE) {
            throw new UnexpectedValueException('Le paquet reçu n\'est pas une réponse SNMP.');
        }

        $p = 0;
        $requestId = self::decodeInteger(self::readTlv($pdu['value'], $p)['value']);
        if ($_requestId !== null && $requestId !== (int) $_requestId) {
            throw new UnexpectedValueException('Réponse à une autre requête.');
        }
        $error = self::decodeInteger(self::readTlv($pdu['value'], $p)['value']);
        self::readTlv($pdu['value'], $p);                          /* error-index */
        $list = self::readTlv($pdu['value'], $p);

        $values = array();
        $v = 0;
        while ($v < strlen($list['value'])) {
            $varbind = self::readTlv($list['value'], $v);
            $b = 0;
            $name = self::readTlv($varbind['value'], $b);
            $value = self::readTlv($varbind['value'], $b);
            $oid = self::decodeOid($name['value']);

            switch ($value['tag']) {
                case self::TAG_NO_SUCH_OBJECT:
                case self::TAG_NO_SUCH_INSTANCE:
                case self::TAG_END_OF_MIB_VIEW:
                case self::TAG_NULL:
                    /* Absent, et non pas nul : la nuance est ce qui empêche de
                     * créer une commande pour une donnée que l'imprimante n'a pas. */
                    break;
                case self::TAG_INTEGER:
                    $values[$oid] = self::decodeInteger($value['value']);
                    break;
                case self::TAG_COUNTER32:
                case self::TAG_GAUGE32:
                case self::TAG_TIMETICKS:
                case self::TAG_COUNTER64:
                    $values[$oid] = self::decodeUnsigned($value['value']);
                    break;
                case self::TAG_OID:
                    $values[$oid] = self::decodeOid($value['value']);
                    break;
                case self::TAG_IPADDRESS:
                    $values[$oid] = implode('.', array_map('ord', str_split($value['value'])));
                    break;
                default:
                    $values[$oid] = $value['value'];
                    break;
            }
        }
        return array('error' => $error, 'values' => $values);
    }

    private static function readTlv($_data, &$_offset) {
        $size = strlen($_data);
        if ($_offset + 2 > $size) {
            throw new UnexpectedValueException('Réponse SNMP tronquée.');
        }
        $tag = ord($_data[$_offset++]);
        $length = ord($_data[$_offset++]);
        if ($length & 0x80) {
            $count = $length & 0x7F;
            if ($count === 0 || $count > 4 || $_offset + $count > $size) {
                throw new UnexpectedValueException('Longueur BER invalide.');
            }
            $length = 0;
            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($_data[$_offset++]);
            }
        }
        if ($_offset + $length > $size) {
            throw new UnexpectedValueException('Réponse SNMP tronquée.');
        }
        $value = (string) substr($_data, $_offset, $length);
        $_offset += $length;
        return array('tag' => $tag, 'value' => $value);
    }

    public static function decodeInteger($_bytes) {
        if ($_bytes === '') {
            return 0;
        }
        $value = (ord($_bytes[0]) & 0x80) ? -1 : 0;
        for ($i = 0; $i < strlen($_bytes); $i++) {
            $value = ($value << 8) | ord($_bytes[$i]);
        }
        return $value;
    }

    public static function decodeUnsigned($_bytes) {
        $value = 0;
        for ($i = 0; $i < strlen($_bytes); $i++) {
            $value = ($value * 256) + ord($_bytes[$i]);
        }
        return is_float($value) ? $value : (int) $value;
    }

    public static function decodeOid($_bytes) {
        if ($_bytes === '') {
            return '';
        }
        $first = ord($_bytes[0]);
        $parts = array(min(2, intdiv($first, 40)), $first - 40 * min(2, intdiv($first, 40)));
        $current = 0;
        for ($i = 1; $i < strlen($_bytes); $i++) {
            $byte = ord($_bytes[$i]);
            $current = ($current << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                $parts[] = $current;
                $current = 0;
            }
        }
        return implode('.', $parts);
    }
}
