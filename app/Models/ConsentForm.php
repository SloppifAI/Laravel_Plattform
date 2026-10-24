<?php

namespace App\Models;

use Database\Factories\ConsentFormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One version of a school's consent form for lesson recordings. A published version is never edited: the school
 * publishes a new one instead, and consents given for an older version count as outdated (Art. 7 Abs. 1 DSGVO:
 * every consent can be traced to the exact text it was given for).
 */
#[Fillable(['school_id', 'version', 'title', 'body', 'purposes', 'published_at', 'created_by'])]
class ConsentForm extends Model
{
    /** @use HasFactory<ConsentFormFactory> */
    use HasFactory;

    /**
     * The purposes parents agree to separately. The audio itself is the basis of the other two.
     *
     * @var array<string, string>
     */
    public const array PURPOSES = [
        'audio' => 'Tonaufnahme des Unterrichts',
        'transcript' => 'Transkript (Text) aus der Aufnahme',
        'ai_analysis' => 'KI-gestützte Auswertung des Transkripts (nur pseudonymisiert)',
    ];

    /**
     * The same purposes as short words for lists.
     *
     * @var array<string, string>
     */
    public const array PURPOSES_SHORT = ['audio' => 'Aufnahme', 'transcript' => 'Transkript', 'ai_analysis' => 'KI-Auswertung'];

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RecordingConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(RecordingConsent::class);
    }

    /**
     * @param  Builder<ConsentForm>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    /**
     * The text for the public page and the letters. Markdown only: raw HTML is stripped and unsafe links are dropped.
     */
    public function html(): string
    {
        return Str::markdown($this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * @return array<string, string>
     */
    public function purposeLabels(): array
    {
        return array_intersect_key(self::PURPOSES, array_flip($this->purposes));
    }

    public static function defaultTitle(): string
    {
        return 'Einwilligung zur Tonaufnahme im Unterricht';
    }

    /**
     * A template in Sie-form for the parents. The school has to read it through and adapt it (with its data protection
     * officer): the values in it come from the school's master data and the platform's retention setting.
     */
    public static function defaultBody(School $school): string
    {
        $days = (int) config('lernplattform.recordings.keep_days', 14);
        $contact = $school->contact_email ?: 'das Sekretariat der Schule';

        return <<<MARKDOWN
        **{$school->name}** möchte einzelne Unterrichtsstunden als reine **Tonaufnahme** aufzeichnen. Dafür brauchen wir Ihre Einwilligung. Sie ist freiwillig.

        ## Was wird aufgezeichnet?

        - Ausschließlich der Ton des Unterrichts. Es gibt keine Bild- und keine Videoaufnahmen.
        - Im Ton können die Stimme und die mündlichen Beiträge Ihres Kindes zu hören sein.
        - Es wird nur aufgezeichnet, wenn für alle anwesenden Schüler:innen der Klasse eine Einwilligung vorliegt.

        ## Wofür wird die Aufnahme verwendet?

        - Zur Unterstützung des Unterrichts: Aus der Aufnahme können ein Transkript (Text) und eine Zusammenfassung für die Lehrkraft entstehen. Die Lehrkraft kann so die Stunde nachbereiten und Schüler:innen gezielter fördern.
        - Eine KI-Auswertung findet nur statt, wenn Sie ihr unten ausdrücklich zustimmen und die Schule die KI-Anbindung aktiviert hat. Die KI erhält dann ausschließlich pseudonymisierten Text, niemals die Tonaufnahme.
        - Für andere Zwecke werden die Aufnahmen nicht verwendet.

        ## Wer hat Zugriff?

        Nur die Lehrkraft, die die Stunde aufnimmt. Die Aufnahme wird nicht an andere Personen oder Stellen weitergegeben.

        ## Wie lange wird gespeichert?

        Tonaufnahmen und Transkripte werden nach {$days} Tagen automatisch gelöscht. Was die Lehrkraft daraus selbst in ihre Unterrichtsnotizen übernimmt, wird nach den üblichen Fristen für Unterrichtsdaten aufbewahrt.

        ## Freiwillig und ohne Nachteile

        Sie entscheiden freiwillig. Wenn Sie nicht einwilligen oder später widerrufen, entstehen Ihrem Kind keine Nachteile. Sie können einzelne Zwecke ablehnen und nur den Rest erlauben, die Tonaufnahme selbst ist aber die Grundlage der anderen Zwecke.

        ## Widerruf

        Sie können Ihre Einwilligung jederzeit mit Wirkung für die Zukunft widerrufen. Nutzen Sie dafür denselben Link, über den Sie die Einwilligung erteilt haben, oder schreiben Sie der Schule. Die Rechtmäßigkeit der Verarbeitung bis zum Widerruf bleibt unberührt (Art. 7 Abs. 3 DSGVO). Möchten Sie, dass vorhandene Aufnahmen früher gelöscht werden, wenden Sie sich bitte an die Schule.

        ## Ihre Rechte und Kontakt

        Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung und Widerspruch sowie das Recht, sich bei der Datenschutzaufsichtsbehörde zu beschweren. Verantwortlich ist {$school->name}, {$school->city}. Kontakt: {$contact}.
        MARKDOWN;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purposes' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
