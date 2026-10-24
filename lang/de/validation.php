<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Die folgenden Sprachzeilen enthalten die Standardfehlermeldungen, die von
    | der Validator-Klasse verwendet werden. Einige dieser Regeln haben mehrere
    | Varianten, wie die Größenregeln. Du kannst jede dieser Meldungen hier
    | anpassen.
    |
    */

    'accepted' => ':attribute muss akzeptiert werden.',
    'accepted_if' => ':attribute muss akzeptiert werden, wenn :other den Wert :value hat.',
    'active_url' => ':attribute muss eine gültige URL sein.',
    'after' => ':attribute muss ein Datum nach :date sein.',
    'after_or_equal' => ':attribute muss ein Datum nach oder am :date sein.',
    'alpha' => ':attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => ':attribute darf nur Buchstaben, Zahlen, Bindestriche und Unterstriche enthalten.',
    'alpha_num' => ':attribute darf nur Buchstaben und Zahlen enthalten.',
    'any_of' => ':attribute ist ungültig.',
    'array' => ':attribute muss ein Array sein.',
    'array_keys' => ':attribute darf nur die folgenden Schlüssel enthalten: :values.',
    'ascii' => ':attribute darf nur alphanumerische Ein-Byte-Zeichen und Symbole enthalten.',
    'base64' => ':attribute muss eine gültige Base64-Zeichenkette sein.',
    'before' => ':attribute muss ein Datum vor :date sein.',
    'before_or_equal' => ':attribute muss ein Datum vor oder am :date sein.',
    'between' => [
        'array' => ':attribute muss zwischen :min und :max Elemente enthalten.',
        'file' => ':attribute muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => ':attribute muss wahr oder falsch sein.',
    'can' => ':attribute enthält einen nicht autorisierten Wert.',
    'confirmed' => 'Die Bestätigung von :attribute stimmt nicht überein.',
    'contains' => ':attribute enthält nicht den erforderlichen Wert.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => ':attribute muss ein gültiges Datum sein.',
    'date_equals' => ':attribute muss dem Datum :date entsprechen.',
    'date_format' => ':attribute muss dem Format :format entsprechen.',
    'decimal' => ':attribute muss :decimal Nachkommastellen haben.',
    'declined' => ':attribute muss abgelehnt werden.',
    'declined_if' => ':attribute muss abgelehnt werden, wenn :other den Wert :value hat.',
    'different' => ':attribute und :other müssen sich unterscheiden.',
    'digits' => ':attribute muss aus :digits Ziffern bestehen.',
    'digits_between' => ':attribute muss zwischen :min und :max Ziffern haben.',
    'dimensions' => ':attribute hat ungültige Bildabmessungen.',
    'distinct' => ':attribute enthält einen doppelten Wert.',
    'doesnt_contain' => ':attribute darf keinen der folgenden Werte enthalten: :values.',
    'doesnt_end_with' => ':attribute darf nicht mit einem der folgenden Werte enden: :values.',
    'doesnt_start_with' => ':attribute darf nicht mit einem der folgenden Werte beginnen: :values.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'encoding' => ':attribute muss in :encoding codiert sein.',
    'ends_with' => ':attribute muss mit einem der folgenden Werte enden: :values.',
    'enum' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'exists' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'extensions' => ':attribute muss eine der folgenden Dateiendungen haben: :values.',
    'file' => ':attribute muss eine Datei sein.',
    'filled' => 'Das Feld :attribute muss einen Wert haben.',
    'gt' => [
        'array' => ':attribute muss mehr als :value Elemente haben.',
        'file' => ':attribute muss größer als :value Kilobyte sein.',
        'numeric' => ':attribute muss größer als :value sein.',
        'string' => ':attribute muss mehr als :value Zeichen lang sein.',
    ],
    'gte' => [
        'array' => ':attribute muss :value Elemente oder mehr haben.',
        'file' => ':attribute muss größer oder gleich :value Kilobyte sein.',
        'numeric' => ':attribute muss größer oder gleich :value sein.',
        'string' => ':attribute muss mindestens :value Zeichen lang sein.',
    ],
    'hex_color' => ':attribute muss eine gültige hexadezimale Farbe sein.',
    'image' => ':attribute muss ein Bild sein.',
    'in' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'in_array' => ':attribute muss in :other vorhanden sein.',
    'in_array_keys' => ':attribute muss mindestens einen der folgenden Schlüssel enthalten: :values.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'ip' => ':attribute muss eine gültige IP-Adresse sein.',
    'ipv4' => ':attribute muss eine gültige IPv4-Adresse sein.',
    'ipv6' => ':attribute muss eine gültige IPv6-Adresse sein.',
    'json' => ':attribute muss eine gültige JSON-Zeichenkette sein.',
    'list' => ':attribute muss eine Liste sein.',
    'lowercase' => ':attribute muss in Kleinbuchstaben geschrieben sein.',
    'lt' => [
        'array' => ':attribute muss weniger als :value Elemente haben.',
        'file' => ':attribute muss kleiner als :value Kilobyte sein.',
        'numeric' => ':attribute muss kleiner als :value sein.',
        'string' => ':attribute muss weniger als :value Zeichen lang sein.',
    ],
    'lte' => [
        'array' => ':attribute darf nicht mehr als :value Elemente haben.',
        'file' => ':attribute muss kleiner oder gleich :value Kilobyte sein.',
        'numeric' => ':attribute muss kleiner oder gleich :value sein.',
        'string' => ':attribute darf höchstens :value Zeichen lang sein.',
    ],
    'mac_address' => ':attribute muss eine gültige MAC-Adresse sein.',
    'max' => [
        'array' => ':attribute darf nicht mehr als :max Elemente haben.',
        'file' => ':attribute darf nicht größer als :max Kilobyte sein.',
        'numeric' => ':attribute darf nicht größer als :max sein.',
        'string' => ':attribute darf nicht länger als :max Zeichen sein.',
    ],
    'max_digits' => ':attribute darf nicht mehr als :max Ziffern haben.',
    'mimes' => ':attribute muss eine Datei vom Typ :values sein.',
    'mimetypes' => ':attribute muss eine Datei vom Typ :values sein.',
    'min' => [
        'array' => ':attribute muss mindestens :min Elemente haben.',
        'file' => ':attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'min_digits' => ':attribute muss mindestens :min Ziffern haben.',
    'missing' => 'Das Feld :attribute darf nicht vorhanden sein.',
    'missing_if' => 'Das Feld :attribute darf nicht vorhanden sein, wenn :other den Wert :value hat.',
    'missing_unless' => 'Das Feld :attribute darf nicht vorhanden sein, es sei denn, :other hat den Wert :value.',
    'missing_with' => 'Das Feld :attribute darf nicht vorhanden sein, wenn :values vorhanden ist.',
    'missing_with_all' => 'Das Feld :attribute darf nicht vorhanden sein, wenn :values vorhanden sind.',
    'multiple_of' => ':attribute muss ein Vielfaches von :value sein.',
    'not_in' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'not_regex' => 'Das Format von :attribute ist ungültig.',
    'numeric' => ':attribute muss eine Zahl sein.',
    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Ziffer enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => ':attribute ist in einem Datenleck aufgetaucht. Bitte wähle ein anderes :attribute.',
    ],
    'present' => 'Das Feld :attribute muss vorhanden sein.',
    'present_if' => 'Das Feld :attribute muss vorhanden sein, wenn :other den Wert :value hat.',
    'present_unless' => 'Das Feld :attribute muss vorhanden sein, es sei denn, :other hat den Wert :value.',
    'present_with' => 'Das Feld :attribute muss vorhanden sein, wenn :values vorhanden ist.',
    'present_with_all' => 'Das Feld :attribute muss vorhanden sein, wenn :values vorhanden sind.',
    'prohibited' => 'Das Feld :attribute ist nicht erlaubt.',
    'prohibited_if' => 'Das Feld :attribute ist nicht erlaubt, wenn :other den Wert :value hat.',
    'prohibited_if_accepted' => 'Das Feld :attribute ist nicht erlaubt, wenn :other akzeptiert wurde.',
    'prohibited_if_declined' => 'Das Feld :attribute ist nicht erlaubt, wenn :other abgelehnt wurde.',
    'prohibited_unless' => 'Das Feld :attribute ist nicht erlaubt, es sei denn, :other hat einen der Werte :values.',
    'prohibits' => 'Das Feld :attribute verhindert, dass :other vorhanden ist.',
    'regex' => 'Das Format von :attribute ist ungültig.',
    'required' => 'Das Feld :attribute ist erforderlich.',
    'required_array_keys' => ':attribute muss Einträge für folgende Schlüssel enthalten: :values.',
    'required_if' => 'Das Feld :attribute ist erforderlich, wenn :other den Wert :value hat.',
    'required_if_accepted' => 'Das Feld :attribute ist erforderlich, wenn :other akzeptiert wurde.',
    'required_if_declined' => 'Das Feld :attribute ist erforderlich, wenn :other abgelehnt wurde.',
    'required_unless' => 'Das Feld :attribute ist erforderlich, es sei denn, :other hat einen der Werte :values.',
    'required_with' => 'Das Feld :attribute ist erforderlich, wenn :values vorhanden ist.',
    'required_with_all' => 'Das Feld :attribute ist erforderlich, wenn :values vorhanden sind.',
    'required_without' => 'Das Feld :attribute ist erforderlich, wenn :values nicht vorhanden ist.',
    'required_without_all' => 'Das Feld :attribute ist erforderlich, wenn keines der Felder :values vorhanden ist.',
    'same' => ':attribute muss mit :other übereinstimmen.',
    'size' => [
        'array' => ':attribute muss genau :size Elemente enthalten.',
        'file' => ':attribute muss genau :size Kilobyte groß sein.',
        'numeric' => ':attribute muss genau :size sein.',
        'string' => ':attribute muss genau :size Zeichen lang sein.',
    ],
    'starts_with' => ':attribute muss mit einem der folgenden Werte beginnen: :values.',
    'string' => ':attribute muss eine Zeichenkette sein.',
    'timezone' => ':attribute muss eine gültige Zeitzone sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'uppercase' => ':attribute muss in Großbuchstaben geschrieben sein.',
    'url' => ':attribute muss eine gültige URL sein.',
    'ulid' => ':attribute muss eine gültige ULID sein.',
    'uuid' => ':attribute muss eine gültige UUID sein.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Hier kannst du eigene Validierungsmeldungen für Attribute festlegen. Die
    | Benennung "attribute.rule" dient dazu, eine bestimmte Sprachzeile für eine
    | Attribut-Regel-Kombination zu hinterlegen.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'benutzerdefinierte-Nachricht',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Die folgenden Sprachzeilen ersetzen den Attribut-Platzhalter durch einen
    | verständlicheren Namen, z. B. "E-Mail-Adresse" statt "email". So werden
    | die Meldungen aussagekräftiger.
    |
    */

    'attributes' => [
        'name' => 'Name',
        'username' => 'Benutzername',
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwortbestätigung',
        'school_class_id' => 'Klasse',
        'role' => 'Rolle',
        'topic' => 'Thema',
        'subject' => 'Fach',
        'notes' => 'Notizen',
        'summary' => 'Zusammenfassung',
        'due_on' => 'Abgabedatum',
        'held_on' => 'Datum der Stunde',
        'join_code' => 'Klassencode',
        'current_password' => 'Aktuelles Passwort',
    ],

];
