## lajennylove/plugin-mdx-storage

A NativePHP Mobile plugin

### Installation

```bash
composer require lajennylove/plugin-mdx-storage
```

### PHP Usage (Livewire/Blade)

Use the `MdxStorage` facade:

@verbatim
<code-snippet name="Using MdxStorage Facade" lang="php">
use Lajennylove\MdxStorage\Facades\MdxStorage;

// Execute the plugin functionality
$result = MdxStorage::execute(['option1' => 'value']);

// Get the current status
$status = MdxStorage::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `MdxStorage::execute()`: Execute the plugin functionality
- `MdxStorage::getStatus()`: Get the current status

### Events

- `MdxStorageCompleted`: Listen with `#[OnNative(MdxStorageCompleted::class)]`

@verbatim
<code-snippet name="Listening for MdxStorage Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Lajennylove\MdxStorage\Events\MdxStorageCompleted;

#[OnNative(MdxStorageCompleted::class)]
public function handleMdxStorageCompleted($result, $id = null)
{
    // Handle the event
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using MdxStorage in JavaScript" lang="javascript">
import { mdxStorage } from '@lajennylove/plugin-mdx-storage';

// Execute the plugin functionality
const result = await mdxStorage.execute({ option1: 'value' });

// Get the current status
const status = await mdxStorage.getStatus();
</code-snippet>
@endverbatim