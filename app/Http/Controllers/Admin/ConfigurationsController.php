<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Configuration;
use Storage;
use Str;
use Carbon\Carbon;

class ConfigurationsController extends Controller
{
    public function admin_index(Request $request)
    {
        $page_title = 'Configuration List';

        $resultQuery = Configuration::select('id', 'name', 'value');

        if ($request->isMethod('get') && $request->input('todo') == 'Filter') {
            if ($request->filled('title')) {
                $resultQuery->where('title', 'like', "%{$request->input('title')}%");
            }
            if ($request->filled('name')) {
                $resultQuery->where('name', 'like', "%{$request->input('name')}%");
            }
        }
        $configurations = $resultQuery->orderBy('order')->paginate(config('Reading.nodes_per_page'));
        return view('admin.configurations.admin_index', compact('configurations', 'page_title'));
    }

    public function admin_prefix(Request $request, $prefix = NULL)
    {


        $page_title = 'Configuration';

        if ($request->isMethod('post')) {

            if ($request->has('Configuration')) {
                $newArr = array();
                $fileNameArr = $this->__imageSave($request);

                foreach ($request->input('Configuration') as $key => $config_value) {


                    if (!isset($config_value['value']) && $config_value['input_type'] == 'checkbox') {
                        $config_value['value'] = 0;
                    } else if ($config_value['input_type'] == 'multiple_checkbox') {
                        if (isset($config_value['value'])) {

                            $config_value['value'] = array_keys($config_value['value']);
                            $config_value['value'] = implode(',',  $config_value['value']);
                        } else {
                            $config_value['value'] = '';
                        }
                    } else if (isset($config_value['value'])) {
                        $config_value['value'] = $config_value['value'];
                    }
                    if (array_key_exists($key, $fileNameArr)) {
                        $config_value['value'] = $fileNameArr[$key];
                    }
                    $res = Configuration::where('id', '=', $key)->update($config_value);
                }
                return redirect()->back()->with('success', 'Configuration is successfully updated.');
            } else {
                return redirect()->back()->with('error', 'Something went wrong, please try again later.');
            }
        } else {
            $page_title = $prefix;
            $configurations = Configuration::select('id', 'name', 'value', 'title', 'description', 'input_type', 'editable', 'weight', 'params', 'order')->where('name', 'LIKE', $prefix . '%')->orderBy('order', 'asc')->get();

            return view('admin.configurations.admin_prefix', compact('configurations', 'prefix', 'page_title'));
        }
    }

    public function admin_view($id = null)
    {
        $configuration = Configuration::select('id', 'name', 'value')->firstWhere('id', $id);
        return view('admin.configurations.admin_view', compact('configuration'));
    }

    public function admin_add(Request $request)
    {
        if ($request->isMethod('post')) {

            $request->validate([
                'Configuration.name' => 'required|unique:configurations,name',
                // Add more validation rules for other fields if needed
            ]);

            $new_configuration = [
                'name'              => $request->input('Configuration.name'),
                'value'             => $request->input('Configuration.value'),
                'title'             => $request->input('Configuration.title'),
                'input_type'        => $request->input('Configuration.input_type'),
                'description'       => $request->input('Configuration.description') ? $request->input('Configuration.description') : '',
                'params'            => $request->input('Configuration.params') ? $request->input('Configuration.params') : '',
                'editable'          => $request->input('Configuration.editable') ? 1 : 0,
            ];

            $res = Configuration::create($new_configuration);

            if ($res) {
                return redirect()->route('admin.configurations.admin_index')->with('success', 'Configuration is successfully saved.');
            } else {
                return redirect()->route('admin.configurations.admin_index')->with('error', 'Something went wrong, please try again later.');
            }
        } else {
            return view('admin.configurations.admin_add');
        }
    }

    public function admin_edit(Request $request, $id)
    {
        $configuration = Configuration::findorFail($id);

        if ($request->isMethod('post')) {

            $request->validate([
                'Configuration.name' => 'required|unique:configurations,name,' . $id,
                // Add more validation rules for other fields if needed
            ]);

            $edit_configuration = [
                'name'                  => $request->input('Configuration.name'),
                'value'                 => $request->input('Configuration.value'),
                'title'                 => $request->input('Configuration.title'),
                'input_type'            => $request->input('Configuration.input_type'),
                'description'           => $request->input('Configuration.description'),
                'params'                => $request->input('Configuration.params'),
                'editable'              => $request->input('Configuration.editable') ? 1 : 0,
            ];

            $res = Configuration::where('id', '=', $id)->update($edit_configuration);

            if ($res) {
                return redirect()->route('admin.configurations.admin_index')->with('success', 'Configuration is successfully updated.');
            } else {
                return redirect()->route('admin.configurations.admin_index')->with('error', 'Something went wrong, please try again later.');
            }
        } else {
            return view('admin.configurations.admin_edit', compact('configuration'));
        }
    }

    public function admin_delete($id = NUll)
    {

        $configuration = Configuration::findorFail($id);
        $res = $configuration->delete();

        if ($res) {
            return redirect()->route('admin.configurations.admin_index')->with('success', 'Configuration is successfully deleted.');
        } else {
            return redirect()->route('admin.configurations.admin_index')->with('error', 'Something went wrong, please try again later.');
        }
    }

    /**
     * Admin moveup
     *
     * @param integer $id
     * @param integer $step
     * @return void
     * @access public
     */
    public function admin_moveup($id, $step = 1)
    {

        $configuration = new Configuration();
        $res = $configuration->moveUp($id, $step);
        if ($res) {
            return redirect()->back()->with('success', 'Moved up is successfully .');
        } else {
            return redirect()->back()->with('error', 'Something went wrong, please try again later.');
        }
    }

    /**
     * Admin moveup
     *
     * @param integer $id
     * @param integer $step
     * @return void
     * @access public
     */
    public function admin_movedown($id, $step = 1)
    {

        $configuration = new Configuration();
        $res = $configuration->moveDown($id, $step);
        if ($res) {
            return redirect()->back()->with('success', 'Moved down is successfully.');
        } else {
            return redirect()->back()->with('error', 'Something went wrong, please try again later.');
        }
    }

    /**
     * image save function
     *
     *
     **/
    private function __imageSave($request)
    {
        $fileNameArr = array();
        if (empty($request->file('Configuration'))) {
            return $fileNameArr;
        }

        foreach ($request->file('Configuration') as $imgKey => $imgValue) {

            if (is_array($imgValue['value'])) {


                foreach ($imgValue['value'] as $image) {
                    $fileName = $image->hashName();

                    $image->storeAs('public/configuration-images', $image->hashName());
                    $fileFullName[] = $fileName;
                }

                $fileName = implode(",", $fileFullName);
            } else {


                $fileName =  $imgValue['value']->getClientOriginalName();
                $file_arr = explode('.', $fileName);
                $name     = $file_arr[0];
                $extension = $file_arr[1];

                $fileName = $name . '-' . time() . '.' . $extension;
                $uploadpath = storage_path('app/public/configuration-images');
                $imgValue['value']->move($uploadpath, $fileName);
            }
            $fileNameArr[$imgKey] = $fileName;
        }


        return $fileNameArr;
    }

    /**
     * image save function For Prefix
     *
     *
     **/
}
