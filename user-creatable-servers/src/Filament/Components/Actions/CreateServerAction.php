<?php

namespace Boy132\UserCreatableServers\Filament\Components\Actions;

use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Exceptions\Service\Deployment\NoViableNodeException;
use App\Filament\Components\Forms\Fields\StartupVariable;
use App\Filament\Server\Pages\Console;
use App\Models\Egg;
use App\Services\Servers\RandomWordService;
use Boy132\UserCreatableServers\Models\UserResourceLimits;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;

class CreateServerAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'create_server';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->visible(fn () => UserResourceLimits::where('user_id', auth()->user()->id)->exists());

        $this->disabled(function () {
            /** @var ?UserResourceLimits $userResourceLimits */
            $userResourceLimits = UserResourceLimits::where('user_id', auth()->user()->id)->first();

            if (!$userResourceLimits) {
                return true;
            }

            return !$userResourceLimits->canCreateServer(1, 1, 1);
        });

        $this->schema(function () {
            /** @var UserResourceLimits $userResourceLimits */
            $userResourceLimits = UserResourceLimits::where('user_id', auth()->user()->id)->firstOrFail();

            return [
                TextInput::make('name')
                    ->label(trans('user-creatable-servers::strings.name'))
                    ->required()
                    ->default(fn () => (new RandomWordService())->word())
                    ->columnSpanFull(),
                Select::make('egg_id')
                    ->label(trans('user-creatable-servers::strings.egg'))
                    ->prefixIcon('tabler-egg')
                    ->options(fn () => Egg::all()->mapWithKeys(fn (Egg $egg) => [$egg->id => $egg->name]))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        $egg = Egg::find($state);

                        $variables = $egg->variables ?? [];
                        $serverVariables = collect();
                        foreach ($variables as $variable) {
                            $serverVariables->add($variable->toArray());
                        }

                        $set('variables', $serverVariables->sortBy(['sort'])->all());
                        for ($i = 0; $i < $serverVariables->count(); $i++) {
                            $set("variables.$i.variable_value", $serverVariables[$i]['default_value']);
                            $set("variables.$i.variable_id", $serverVariables[$i]['id']);
                        }
                    }),
                TextInput::make('cpu')
                    ->label(trans('user-creatable-servers::strings.cpu'))
                    ->required()
                    ->numeric()
                    ->minValue($userResourceLimits->cpu > 0 ? 1 : 0)
                    ->maxValue($userResourceLimits->getCpuLeft())
                    ->suffix('%'),
                TextInput::make('memory')
                    ->label(trans('user-creatable-servers::strings.memory'))
                    ->required()
                    ->numeric()
                    ->minValue($userResourceLimits->memory > 0 ? 1 : 0)
                    ->maxValue($userResourceLimits->getMemoryLeft())
                    ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB'),
                TextInput::make('disk')
                    ->label(trans('user-creatable-servers::strings.disk'))
                    ->required()
                    ->numeric()
                    ->minValue($userResourceLimits->disk > 0 ? 1 : 0)
                    ->maxValue($userResourceLimits->getDiskLeft())
                    ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB'),
                Repeater::make('variables')
                    ->label(trans('user-creatable-servers::strings.variables'))
                    ->hidden(fn (Get $get) => !$get('egg_id'))
                    ->grid(2)
                    ->reorderable(false)
                    ->addable(false)
                    ->deletable(false)
                    ->default([])
                    ->hidden(fn ($state) => empty($state))
                    ->schema([
                        StartupVariable::make('variable_value')
                            ->fromForm()
                            ->disabled(false),
                    ]),
            ];
        });

        $this->action(function (array $data) {
            try {
                /** @var UserResourceLimits $userResourceLimits */
                $userResourceLimits = UserResourceLimits::where('user_id', auth()->user()->id)->firstOrFail();

                if ($server = $userResourceLimits->createServer($data['name'], $data['egg_id'], $data['cpu'], $data['memory'], $data['disk'], Arr::mapWithKeys($data['variables'], fn ($value) => [$value['env_variable'] => $value['variable_value']]))) {
                    redirect(Console::getUrl(panel: 'server', tenant: $server));
                }
            } catch (Exception $exception) {
                report($exception);

                if ($exception instanceof NoViableNodeException) {
                    Notification::make()
                        ->title(trans('user-creatable-servers::strings.notifications.server_creation_failed'))
                        ->body(trans('user-creatable-servers::strings.notifications.no_viable_node_found'))
                        ->danger()
                        ->send();
                } elseif ($exception instanceof NoViableAllocationException) {
                    Notification::make()
                        ->title(trans('user-creatable-servers::strings.notifications.server_creation_failed'))
                        ->body(trans('user-creatable-servers::strings.notifications.no_viable_allocation_found'))
                        ->danger()
                        ->send();
                } else {
                    Notification::make()
                        ->title(trans('user-creatable-servers::strings.notifications.server_creation_failed'))
                        ->body(trans('user-creatable-servers::strings.notifications.unknown_server_creation_error'))
                        ->danger()
                        ->send();
                }
            }
        });
    }
}
