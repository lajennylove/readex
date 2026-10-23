# MdxStorage Plugin for NativePHP Mobile

A NativePHP Mobile plugin

## Installation

```bash
composer require lajennylove/plugin-mdx-storage
```

## Usage

```php
use Lajennylove\MdxStorage\Facades\MdxStorage;

// Execute functionality
$result = MdxStorage::execute(['option1' => 'value']);

// Get status
$status = MdxStorage::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Lajennylove\MdxStorage\Events\MdxStorageCompleted')]
public function handleMdxStorageCompleted($result, $id = null)
{
    // Handle the event
}
```

## License

MIT