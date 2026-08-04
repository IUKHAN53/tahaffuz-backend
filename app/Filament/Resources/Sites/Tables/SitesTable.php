<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SitesTable
{
    /** Google Maps pin URL for a site's coordinates, or null if missing. */
    public static function mapUrl(Site $site): ?string
    {
        if ($site->latitude === null || $site->longitude === null) {
            return null;
        }

        return "https://www.google.com/maps/search/?api=1&query={$site->latitude},{$site->longitude}";
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fix_site')
                    ->label('Fixed Site')
                    ->weight('bold')
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('outreach_site')
                    ->label('Outreach Site')
                    ->wrap()
                    ->placeholder('Fixed Site')
                    ->searchable(),
                TextColumn::make('district')
                    ->label('District')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('union_council')
                    ->label('Union Council')
                    ->badge()
                    ->color('success')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('timing')
                    ->label('Timing')
                    ->state(fn (Site $r): string => $r->timingLabel())
                    ->icon('heroicon-o-clock')
                    ->toggleable(),
                TextColumn::make('vaccine_days')
                    ->label('BCG / MR day')
                    ->state(function (Site $r): string {
                        $parts = [];
                        if ($d = $r->bcgDay()) {
                            $parts[] = 'BCG '.Site::DAY_LABELS[$d];
                        }
                        if ($d = $r->mrDay()) {
                            $parts[] = 'MR '.Site::DAY_LABELS[$d];
                        }

                        return $parts ? implode(' · ', $parts) : '—';
                    })
                    ->toggleable(),
                TextColumn::make('coordinates')
                    ->label('Location (tap to open map)')
                    ->state(fn (Site $r): string => $r->latitude !== null && $r->longitude !== null
                        ? "{$r->latitude}, {$r->longitude}"
                        : 'No coordinates')
                    ->icon('heroicon-o-map-pin')
                    ->color(fn (Site $r): string => self::mapUrl($r) ? 'primary' : 'gray')
                    ->url(fn (Site $r): ?string => self::mapUrl($r))
                    ->openUrlInNewTab()
                    ->copyable()
                    ->copyableState(fn (Site $r): string => $r->latitude !== null
                        ? "{$r->latitude},{$r->longitude}"
                        : ''),
            ])
            ->filters([
                SelectFilter::make('district')
                    ->options(fn () => Site::query()
                        ->whereNotNull('district')
                        ->distinct()
                        ->orderBy('district')
                        ->pluck('district', 'district')
                        ->all()),
                SelectFilter::make('union_council')
                    ->label('Union Council')
                    ->searchable()
                    ->options(fn () => Site::query()
                        ->whereNotNull('union_council')
                        ->distinct()
                        ->orderBy('union_council')
                        ->pluck('union_council', 'union_council')
                        ->all()),
                Filter::make('missing_coordinates')
                    ->label('Missing coordinates')
                    ->query(fn (Builder $query) => $query->whereNull('latitude')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Details'),
                // Per-site override of the standard opening hours: pick the
                // operating DAYS and the open/close TIMES. Values matching the
                // standard are stored as NULL so those sites keep tracking any
                // future change to the default.
                Action::make('editTiming')
                    ->label('Timing')
                    ->icon('heroicon-o-clock')
                    ->color('warning')
                    ->modalHeading('Site timing')
                    ->modalDescription(fn (Site $r): string => 'Opening days and hours shown on this site\'s card. Standard: '
                        .(new Site)->timingLabel().'.')
                    ->schema([
                        CheckboxList::make('timing_days')
                            ->label('Open days')
                            ->options(Site::DAY_LABELS)
                            ->columns(4)
                            ->required()
                            ->minItems(1),
                        TimePicker::make('open_time')
                            ->label('Opens at')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('close_time')
                            ->label('Closes at')
                            ->seconds(false)
                            ->required()
                            ->after('open_time'),
                        TimePicker::make('break_start')
                            ->label('Break from')
                            ->helperText('Optional mid-day break (e.g. prayer/lunch). Leave both empty for no break.')
                            ->seconds(false)
                            ->after('open_time')
                            ->before('close_time')
                            ->requiredWith('break_end'),
                        TimePicker::make('break_end')
                            ->label('Break until')
                            ->seconds(false)
                            ->after('break_start')
                            ->before('close_time')
                            ->requiredWith('break_start'),
                        Select::make('bcg_day')
                            ->label('BCG session day')
                            ->options(Site::DAY_LABELS)
                            ->placeholder('Not scheduled')
                            ->helperText('BCG and MR vials are opened on fixed weekdays (SHR UC schedule).'),
                        Select::make('mr_day')
                            ->label('MR session day')
                            ->options(Site::DAY_LABELS)
                            ->placeholder('Not scheduled'),
                    ])
                    ->fillForm(fn (Site $r): array => [
                        'timing_days' => $r->timingDays(),
                        'open_time' => $r->openTime(),
                        'close_time' => $r->closeTime(),
                        'break_start' => $r->breakStart(),
                        'break_end' => $r->breakEnd(),
                        'bcg_day' => $r->bcgDay(),
                        'mr_day' => $r->mrDay(),
                    ])
                    ->action(function (array $data, Site $r): void {
                        // Normalize picker values ("H:i:s" → "HH:MM") and week order.
                        $days = array_values(array_intersect(
                            array_keys(Site::DAY_LABELS),
                            array_map(fn ($d) => mb_strtolower(trim((string) $d)), (array) ($data['timing_days'] ?? [])),
                        ));
                        $open = Site::normalizeTime($data['open_time'] ?? null) ?? Site::DEFAULT_OPEN;
                        $close = Site::normalizeTime($data['close_time'] ?? null) ?? Site::DEFAULT_CLOSE;
                        // Break is stored only as a complete window; a lone
                        // start or end means no break.
                        $breakStart = Site::normalizeTime($data['break_start'] ?? null);
                        $breakEnd = Site::normalizeTime($data['break_end'] ?? null);
                        if ($breakStart === null || $breakEnd === null) {
                            $breakStart = $breakEnd = null;
                        }

                        // Store NULLs when everything matches the standard hours,
                        // so unchanged sites follow the default if it ever moves.
                        $isDefault = $days === Site::DEFAULT_DAYS
                            && $open === Site::DEFAULT_OPEN
                            && $close === Site::DEFAULT_CLOSE;

                        $r->update([
                            'timing_days' => $isDefault || empty($days) ? null : $days,
                            'open_time' => $isDefault ? null : $open,
                            'close_time' => $isDefault ? null : $close,
                            'break_start' => $breakStart,
                            'break_end' => $breakEnd,
                            'bcg_day' => Site::normalizeDay($data['bcg_day'] ?? null),
                            'mr_day' => Site::normalizeDay($data['mr_day'] ?? null),
                        ]);

                        Notification::make()
                            ->title('Timing updated: '.$r->refresh()->timingLabel())
                            ->success()
                            ->send();
                    }),
                Action::make('openMap')
                    ->label('Maps')
                    ->icon('heroicon-o-map')
                    ->color('primary')
                    ->url(fn (Site $r): ?string => self::mapUrl($r))
                    ->openUrlInNewTab()
                    ->hidden(fn (Site $r): bool => self::mapUrl($r) === null),
            ])
            ->defaultSort('district')
            ->paginated([25, 50, 100]);
    }
}
