<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public function index()
    {
        return PatientResource::collection(Patient::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mrn' => ['required', 'string', 'max:50', 'unique:patients,mrn'],
            'medical_notes' => ['nullable', 'string'],
        ]);
        $patient = Patient::create($data);
        return (new PatientResource($patient))->response()->setStatusCode(201);
    }

    public function show(Patient $patient)
    {
        return new PatientResource($patient);
    }

    public function update(Request $request, Patient $patient)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'mrn' => ['sometimes', 'string', 'max:50', Rule::unique('patients', 'mrn')->ignore($patient->id)],
            'medical_notes' => ['nullable', 'string'],
        ]);
        $patient->update($data);
        return new PatientResource($patient);
    }

    public function destroy(Patient $patient)
    {
        $patient->delete();
        return response()->json(['message' => 'Patient deleted']);
    }
}
